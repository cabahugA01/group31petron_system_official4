<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'admin_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Access control ──────────────────────────────────────────────────────────
if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// ── Superadmin: station selection (defaults to first station if none assigned) ─
if ($role === 'superadmin' && (int)$station_id <= 0) {
    // Try to get selected station from query param
    $selected_sid = (int)($_GET['station_id'] ?? 0);
    if ($selected_sid > 0) {
        $station_id = $selected_sid;
    } else {
        // Default to first available station
        try {
            $first_s = $pdo->query("SELECT id FROM stations ORDER BY id LIMIT 1")->fetchColumn();
            $station_id = $first_s ?: 0;
        } catch (Exception $e) { $station_id = 0; }
    }
}

// ── Ensure job_order_service_types table exists ────────────────────────────
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
} catch (Exception $e) { /* table already exists */ }

// ── Handle Approvals / Rejections ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Preserve the active tab across POST redirects
    $redirect_tab = trim($_POST['active_tab'] ?? 'fuel');
    if (!in_array($redirect_tab, ['fuel', 'merch', 'services'])) $redirect_tab = 'fuel';

    if ($action === 'approve_price') {
        $approval_id = (int)$_POST['approval_id'];
        $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
        $stmt->execute([$approval_id]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pending) {
            $ptype = $pending['product_type'] ?? '';
            // Support both new_price (new schema) and new_value (legacy schema)
            $new_price_val = $pending['new_price'] ?? $pending['new_value'] ?? 0;
            $new_cost_val  = $pending['new_cost']  ?? $pending['new_value'] ?? 0;
            $pid           = (int)($pending['product_id'] ?? 0);

            if ($ptype === 'merchandise') {
                $pdo->prepare("UPDATE inventory_products SET unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_cost_val, $new_price_val, $pid]);
            } elseif ($ptype === 'service_type' || $ptype === 'service') {
                $svc_id = (int)($pending['service_type_id'] ?? $pid);
                $pdo->prepare("UPDATE job_order_service_types SET service_price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_price_val, $svc_id]);
            } else {
                // covers 'fuel' and 'fuel_inventory'
                $fuel_id = (int)($pending['fuel_type_id'] ?? $pid);
                $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, last_updated=NOW() WHERE id=?")
                    ->execute([$new_price_val, $fuel_id]);
            }
            // Update status — write to both admin_id and reviewed_by for compatibility
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$me['id'], $me['id'], $approval_id]);
            log_activity($pdo, $me['id'], 'Approve Price',
                "Admin approved price change for {$ptype} ID {$pid}. New value: {$new_price_val}");
            $_SESSION['success'] = "Price change approved successfully!";
        }
    } elseif ($action === 'reject_price') {
        $approval_id = (int)$_POST['approval_id'];
        $remarks = trim($_POST['remarks'] ?? '');
        $stmt = $pdo->prepare("UPDATE pending_price_approvals SET status='rejected', rejection_reason=?, reviewer_notes=?, admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
        $stmt->execute([$remarks, $remarks, $me['id'], $me['id'], $approval_id]);
        if ($stmt->rowCount() > 0) {
            log_activity($pdo, $me['id'], 'Reject Price',
                "Admin rejected price change (Approval ID $approval_id). Remarks: $remarks");
            $_SESSION['success'] = "Price change rejected.";
        }
    }
    header("Location: admin_set_prices.php?tab=" . urlencode($redirect_tab));
    exit;
}



// ── Fetch station name ──────────────────────────────────────────────────────────────
$station_name = 'Unknown Station';
try {
    $stmt_sn = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $stmt_sn->execute([$station_id]);
    $station_name = $stmt_sn->fetchColumn() ?: 'Unknown Station';
} catch (Exception $e) { /* silent */ }

// Helper function to get the canonical 5 fuel types
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
            return 'XCS Plus';
        } elseif (strpos($name_lower, 'xtra') !== false || strpos($name_lower, 'unl') !== false || strpos($name_lower, 'advance') !== false) {
            return 'XTR ADVANCE';
        }
        return $name;
    }
}

// ── Fetch fuel inventory ────────────────────────────────────────────────────
try {
    $TANK_CONFIG_17 = get_tank_config();

    $target_sid = $station_id;
    // Check if we have inventory for this station. If not, default to station 1
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ?");
    $check_stmt->execute([$target_sid]);
    if ((int)$check_stmt->fetchColumn() === 0) {
        $target_sid = 1;
    }

    $fi_lookup = [];
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
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
    $s = $pdo->prepare("SELECT fuel_type_id, COALESCE(new_price, new_value) AS new_value, status, id AS approval_id FROM pending_price_approvals WHERE station_id = ? AND product_type IN ('fuel', 'fuel_inventory') AND status = 'pending'");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p_row) {
        $pending_approvals[(int)$p_row['fuel_type_id']] = $p_row;
    }

    foreach ($TANK_CONFIG_17 as $tc) {
        $ft_key = strtolower(trim($tc['fuel_type']));
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
} catch (Exception $e) {
    $fuel_products = [];
    error_log('[admin_set_prices] fuel error: ' . $e->getMessage());
}

// ── Fetch merchandise grouped by category ───────────────────────────────────
$merch_by_cat   = [];
$merch_all      = [];
$merch_stats    = ['total' => 0, 'valid_price' => 0, 'below_cost' => 0, 'unpriced' => 0];
$all_categories = [];

try {
    $stmt = $pdo->prepare("
        SELECT i.id, i.category, i.product_name, i.sku, i.size,
               COALESCE(si.unit, 'pcs') AS unit,
               i.unit_cost, i.supplier,
               i.unit_price, i.stock_quantity, i.stock, i.created_at,
               COALESCE(p.new_cost, p.new_value)  AS pending_cost,
               COALESCE(p.new_price, p.new_value) AS pending_price,
               p.status AS approval_status,
               p.id     AS approval_id
        FROM inventory_products i
        LEFT JOIN station_inventory si
               ON si.product_id = i.id
              AND si.station_id = ?
        LEFT JOIN pending_price_approvals p
               ON i.id = p.product_id
              AND p.product_type = 'merchandise'
              AND p.status = 'pending'
              AND p.station_id = ?
        WHERE i.category != 'Fuel'
          AND LOWER(COALESCE(i.status,'active')) != 'inactive'
        ORDER BY i.category, i.product_name
    ");
    $stmt->execute([$station_id, $station_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Fallback to station 1 if no merch for this station
    if (empty($rows)) {
        $stmt->execute([1, 1]);
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
}

$all_categories = array_keys($all_categories);
sort($all_categories);

// ── Log page view ────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Product Pricing',
        "Admin viewed pricing for station {$station_id}");
} catch (Exception $e) { /* silent */ }

// ── Fetch service types with pending approvals ─────────────────────────────
$service_types = [];
$service_error = null;
try {
    $stmt = $pdo->query("
        SELECT s.id, s.service_name, s.service_key, s.service_price,
               s.status, s.active,
               p.new_price  AS pending_price,
               p.old_price,
               p.manager_id AS pending_manager_id,
               p.status     AS approval_status,
               p.id         AS approval_id
        FROM job_order_service_types s
        LEFT JOIN pending_price_approvals p
               ON s.id = p.product_id
              AND p.product_type = 'service_type'
              AND p.status = 'pending'
        ORDER BY s.service_name
    ");
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add manager names in a second pass
    foreach ($service_types as &$svc) {
        $svc['manager_name'] = null;
        if (!empty($svc['pending_manager_id'])) {
            try {
                $uStmt = $pdo->prepare("SELECT COALESCE(CONCAT(first_name,' ',last_name), username) FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([$svc['pending_manager_id']]);
                $svc['manager_name'] = $uStmt->fetchColumn() ?: 'Unknown';
            } catch (Exception $ue) {
                $svc['manager_name'] = 'Unknown';
            }
        }
    }
    unset($svc);
} catch (Exception $e) {
    $service_types = [];
    $service_error = null; // suppress debug output in production
    error_log("[admin_set_prices] service types error: " . $e->getMessage());
}

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
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 16px 18px; text-align: center;
}
.summary-card .s-num  { font-size: 28px; font-weight: 700; line-height: 1; text-decoration: none !important; }
.summary-card .s-lbl  { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 500; }
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
.toolbar input[type="text"] {  }
.toolbar input[type="text"]:focus,
.toolbar select:focus { outline: none; border-color: #002F6C; box-shadow: 0 0 0 2px rgba(0,47,108,.12); }

/* Readonly notice */
.readonly-notice {
    display: inline-flex; align-items: center; gap: 6px;
    background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
    border-radius: 20px; padding: 4px 14px; font-size: 12px; font-weight: 600;
}

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

/* Export buttons */
.btn-export-csv { background: #16a34a; color: #fff; border: none; }
.btn-export-csv:hover { background: #15803d; }
.btn-export-pdf { background: #7c3aed; color: #fff; border: none; }
.btn-export-pdf:hover { background: #6d28d9; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar input[type="text"] { min-width: unset; width: 100%; }
}
</style>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="page-head" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="h1"><i class="fas fa-tags"></i> Product &amp; Pricing Overview</h1>
        <div class="sub">Product &amp; Pricing Overview</div>
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
        </div>
        <div class="table-wrap" style="overflow-x:hidden;">
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
                        <th style="text-align: center;">Action</th>
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
                    <?php foreach ($fuel_products as $f):
                        $level    = (float)($f['current_stock'] ?? 0);
                        $critical = (float)($f['critical_level'] ?? 0);
                        $capacity = (float)($f['capacity'] ?? 0);
                        
                        $status_label = $f['status'] ?? 'Normal';
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
                            <?php elseif ($status_label === 'Low'): ?>
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
                        <td style="text-align: center; vertical-align: middle;">
                            <?php if ($f['approval_status'] === 'pending'): ?>
                                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                                    <div style="font-size:11px; color:#b45309; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-bottom:4px;">
                                        <strong>Proposed: ₱<?php echo number_format($f['pending_price'], 2); ?></strong>
                                    </div>
                                    <div style="display:flex; gap:4px;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="approve_price">
                                            <input type="hidden" name="approval_id" value="<?php echo $f['approval_id']; ?>">
                                            <input type="hidden" name="active_tab" value="fuel">
                                            <button type="submit" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;transition:all 0.2s;" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'"><i class="fas fa-check"></i> Approve</button>
                                        </form>
                                        <button type="button" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;transition:all 0.2s;" onclick="openRejectModal(<?php echo $f['approval_id']; ?>, 'fuel')" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'"><i class="fas fa-times"></i> Reject</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="muted" style="font-size:11px;">&mdash;</span>
                            <?php endif; ?>
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
    </div>

    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x:hidden;">
            <table class="pricing-table" id="merchTable">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>UOM</th>
                        <th>Cost (&#8369;)</th>
                        <th>Price (&#8369;)</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th style="text-align: center;">Action</th>
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
                        $margin = $price - $cost;

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
                        <td class="muted"><?php echo htmlspecialchars(format_merch_unit($item['unit'] ?? 'pcs')); ?></td>
                        <td style="color:#64748b;">&#8369;<?php echo number_format($cost, 2); ?></td>
                        <td>
                            <?php if ($no_price): ?>
                                <span class="badge badge-noprice">No Price Set</span>
                            <?php else: ?>
                                <strong style="color:<?php echo $below_cost ? '#dc2626' : '#002F6C'; ?>">
                                    &#8369;<?php echo number_format($price, 2); ?>
                                </strong>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($stock, 0); ?></td>
                        <td><span class="badge <?php echo $st_class; ?>"><?php echo $st_label; ?></span></td>
                        <td style="text-align: center; vertical-align: middle;">
                            <?php if (($item['approval_status'] ?? '') === 'pending'): ?>
                                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                                    <div style="font-size:11px; color:#b45309; background:#fef3c7; padding:2px 6px; border-radius:4px; margin-bottom:4px; text-align:left;">
                                        Proposed Cost: ₱<?php echo number_format($item['pending_cost'], 2); ?><br>
                                        Proposed Price: ₱<?php echo number_format($item['pending_price'], 2); ?>
                                    </div>
                                    <div style="display:flex; gap:4px;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="approve_price">
                                            <input type="hidden" name="approval_id" value="<?php echo $item['approval_id']; ?>">
                                            <input type="hidden" name="active_tab" value="merch">
                                            <button type="submit" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;transition:all 0.2s;" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'"><i class="fas fa-check"></i> Approve</button>
                                        </form>
                                        <button type="button" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;transition:all 0.2s;" onclick="openRejectModal(<?php echo $item['approval_id']; ?>, 'merch')" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'"><i class="fas fa-times"></i> Reject</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="muted" style="font-size:11px;">&mdash;</span>
                            <?php endif; ?>
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
    
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-wrench"></i> Service Types</strong>
            <div style="color:#64748b;font-size:12px;">
                Found <?php echo count($service_types); ?> service type(s)
                <?php 
                $pendingCount = 0;
                foreach ($service_types as $svc) {
                    if (!empty($svc['pending_price'])) $pendingCount++;
                }
                if ($pendingCount > 0): ?>
                    | <span style="color:#d97706;font-weight:600;"><i class="fas fa-clock"></i> <?php echo $pendingCount; ?> pending approval(s)</span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (empty($service_types)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8;">
                <i class="fas fa-wrench" style="font-size:32px;margin-bottom:10px;display:block;"></i>
                No service types found.
            </div>
        <?php else: ?>
            <div class="table-wrap" style="overflow:hidden;">
                <table class="pricing-table">
                    <thead>
                        <tr>
                            <th style="width:200px;">Service Name</th>
                            <th style="width:140px;">Service Key</th>
                            <th style="width:100px;">Current Price (&#8369;)</th>
                            <th style="width:100px;">Pending Price (&#8369;)</th>
                            <th style="width:110px;">Change</th>
                            <th style="width:100px;">Status</th>
                            <th style="width:120px;">Manager</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($service_types as $svc): 
                            $hasPending = !empty($svc['pending_price']);
                            $currentPrice = (float)$svc['service_price'];
                            $pendingPrice = (float)($svc['pending_price'] ?? 0);
                            $oldPrice = (float)($svc['old_price'] ?? $currentPrice);
                            $priceChange = $pendingPrice - $oldPrice;
                            $changePercent = $oldPrice > 0 ? (($priceChange / $oldPrice) * 100) : 0;
                            
                            // Use active column (1 or 0) instead of status
                            $isServiceActive = (int)($svc['active'] ?? 1) === 1;
                            $statusDisplay = $isServiceActive ? 'Active' : 'Inactive';
                            $statusColor = $isServiceActive ? '#16a34a' : '#dc2626';
                        ?>
                        <tr>
                            <!-- Service Name -->
                            <td>
                                <strong><?php echo htmlspecialchars($svc['service_name']); ?></strong>
                            </td>
                            
                            <!-- Service Key -->
                            <td>
                                <span style="font-family:monospace;color:#64748b;font-size:12px;">
                                    <?php echo htmlspecialchars($svc['service_key']); ?>
                                </span>
                            </td>
                            
                            <!-- Current Price -->
                            <td>
                                <strong style="color:#002F6C;">&#8369;<?php echo number_format($currentPrice, 2); ?></strong>
                            </td>
                            
                            <!-- Pending Price -->
                            <td>
                                <?php if ($hasPending): ?>
                                    <strong style="color:#d97706;">&#8369;<?php echo number_format($pendingPrice, 2); ?></strong>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Change -->
                            <td>
                                <?php if ($hasPending): ?>
                                    <div style="display:flex;flex-direction:column;gap:2px;">
                                        <span style="color:<?php echo $priceChange >= 0 ? '#16a34a' : '#dc2626'; ?>;font-weight:700;font-size:12px;">
                                            <?php echo $priceChange >= 0 ? '+' : ''; ?>&#8369;<?php echo number_format(abs($priceChange), 2); ?>
                                        </span>
                                        <span style="color:<?php echo $priceChange >= 0 ? '#16a34a' : '#dc2626'; ?>;font-size:11px;">
                                            <?php echo number_format(abs($changePercent), 1); ?>%
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Status -->
                            <td>
                                <?php if ($hasPending): ?>
                                    <span class="badge" style="background:#fef3c7;color:#92400e;">PENDING APPROVAL</span>
                                <?php else: ?>
                                    <span style="color:<?php echo $statusColor; ?>;font-weight:600;"><?php echo $statusDisplay; ?></span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Manager -->
                            <td>
                                <?php if ($hasPending): ?>
                                    <?php echo htmlspecialchars($svc['manager_name'] ?? 'Unknown'); ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Action -->
                            <td style="text-align:center;vertical-align:middle;">
                                <?php if ($hasPending): ?>
                                    <div style="display:flex;gap:4px;justify-content:center;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="approve_price">
                                            <input type="hidden" name="approval_id" value="<?php echo (int)$svc['approval_id']; ?>">
                                            <input type="hidden" name="active_tab" value="services">
                                            <button type="submit" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;transition:all 0.2s;" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <button type="button" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;transition:all 0.2s;" onclick="openRejectModal(<?php echo (int)$svc['approval_id']; ?>, 'services')" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size:11px;">No action needed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Rejection Modal -->
<style>
/* Modal styles matching transaction module design */
.modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.modal.open { display:flex; }
.modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; background:#fff; border-bottom:1px solid #e9ecef; flex-shrink:0; }
.modal-header h3 { margin:0; font-size:1.05rem; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px; }
.modal-body { padding:24px; flex:1; overflow-y:auto; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid #e9ecef; background:#fff; flex-shrink:0; }
.modal-footer .btn { padding:10px 20px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; border:none; }
.modal-footer .btn-cancel { background:#fff; color:#475569; border:1px solid #cbd5e1; }
.modal-footer .btn-cancel:hover { background:#f8fafc; border-color:#94a3b8; }
.modal-footer .btn-reject { background:#dc2626; color:#fff; }
.modal-footer .btn-reject:hover { background:#b91c1c; }
</style>
<div class="modal" id="rejectModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Reject Price Proposal</h3>
    </div>
    <form method="post" id="rejectForm">
      <div class="modal-body">
          <input type="hidden" name="action" value="reject_price">
          <input type="hidden" name="approval_id" id="rejectApprovalId" value="">
          <input type="hidden" name="active_tab" id="rejectActiveTab" value="fuel">
          <label style="display:block; margin-bottom:8px; font-weight:600; font-size:13px; color:#1e293b;">
            Reason for Rejection <span style="color:#dc2626;">*</span>
          </label>
          <textarea name="remarks" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; resize:vertical; min-height:100px; transition:border-color .15s;" placeholder="Provide detailed remarks for the manager regarding the price rejection..." required onfocus="this.style.borderColor='#002F70';this.style.boxShadow='0 0 0 3px rgba(0,47,112,.1)'" onblur="this.style.borderColor='#cbd5e1';this.style.boxShadow='none'"></textarea>
          <p style="margin-top:8px; font-size:12px; color:#64748b;">
            <i class="fas fa-info-circle"></i> This feedback will be sent to the manager who submitted the price change request.
          </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-reject">Reject Proposal</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRejectModal(id, tab) {
    document.getElementById('rejectApprovalId').value = id;
    document.getElementById('rejectActiveTab').value  = tab || 'fuel';
    document.getElementById('rejectModal').classList.add('open');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}
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

// No DOMContentLoaded override — PHP already sets the correct active class server-side

// ── Merchandise filter ───────────────────────────────────────────────────────
function filterTable() {
    // Check if merchandise tab is active to prevent console errors
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

    // Track which categories have visible rows and how many
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

    // Show/hide category header rows and update counts
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

    var countEl = document.getElementById('visibleCount');
    if (countEl) countEl.textContent = 'Showing ' + visible + ' product' + (visible !== 1 ? 's' : '');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
