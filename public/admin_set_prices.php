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
                $target_station_id = (int)($pending['station_id'] ?? $station_id);
                if ($target_station_id <= 0) $target_station_id = (int)$station_id;

                try {
                    $pdo->prepare("UPDATE inventory_products SET unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $pid]);
                } catch (Exception $legacy_error) {
                    // Current inventory source uses products + station_inventory.
                }

                $pdo->prepare("UPDATE products SET cost=?, price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_cost_val, $new_price_val, $pid]);

                $si_stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $si_stmt->execute([$target_station_id, $pid]);
                $si_id = (int)($si_stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    $pdo->prepare("UPDATE station_inventory SET cost=?, price=?, last_updated=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $si_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, status, last_updated) VALUES (?, ?, 0, ?, ?, 'active', NOW())")
                        ->execute([$target_station_id, $pid, $new_cost_val, $new_price_val]);
                }
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
    $fi_status_by_id = [];
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
    $s = $pdo->prepare("SELECT fuel_type_id, COALESCE(new_price, new_value) AS new_value, status, id AS approval_id FROM pending_price_approvals WHERE station_id = ? AND product_type IN ('fuel', 'fuel_inventory') AND status = 'pending'");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p_row) {
        $pending_approvals[(int)$p_row['fuel_type_id']] = $p_row;
    }

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

        $price = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);
        $timestamp = $inv['last_updated'] ?? null;
        $critical_level = $inv ? (float)($inv['critical_level'] ?? 0) : 300;

        $inv_id = $inv['id'] ?? null;
        $app = $inv_id ? ($pending_approvals[(int)$inv_id] ?? null) : null;

        $fuel_products[] = [
            'id'             => $inv_id,
            'pump_id'        => $tc['tanker_num'],
            'ugt_no'         => $tc['tank'],
            'tank_label'     => $tc['label'],
            'raw_fuel_type'  => $tc['fuel_type'],
            'capacity'       => $capacity,
            'current_stock'  => $ending_system,
            'critical_level' => $critical_level,
            'status'         => $status,
            'inv_status'     => $inv_id ? ($fi_status_by_id[(int)$inv_id] ?? 'active') : 'active',
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
}

// ── Admin Summary Metrics ───────────────────────────────────────────────────
$approved_today_count = 0;
$pending_requests_count = 0;
try {
    $approved_today_count = (int)$pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals
        WHERE status = 'approved' AND (DATE(reviewed_at) = CURDATE() OR DATE(updated_at) = CURDATE())
    ")->fetchColumn();

    $pending_requests_count = (int)$pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals
        WHERE status = 'pending' AND product_type = 'merchandise'
    ")->fetchColumn();
} catch (Exception $e) {}


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
    table-layout: auto !important;
    box-sizing: border-box !important;
}

/* == Tab bar styling (Matches Manager Clean Design) == */
.ato-tab-bar { display:flex;gap:0;border-bottom:2px solid #dee2e6;margin-bottom:18px; }
.ato-tab { display:inline-flex;align-items:center;gap:7px;padding:10px 22px;font-size:13px;font-weight:600;color:#6c757d;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s;white-space:nowrap; cursor:pointer; }
.ato-tab:hover { color:#002F6C; }
.ato-tab.active { color:#002F6C;border-bottom-color:#002F6C;background:#f8fbff;border-radius:6px 6px 0 0; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* == Action buttons — clean outline style (same as Manager) == */
.act-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 4px 8px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1.2;
    width: 100%;
    max-width: 110px;
    margin-bottom: 3px;
    transition: all .18s ease;
    background: #ffffff !important;
    border: 1px solid #cbd5e1;
    color: #475569;
    text-decoration: none;
    box-sizing: border-box;
}
.act-btn:last-child { margin-bottom: 0; }
.act-btn-edit { color: #002F6C !important; border-color: #002F6C !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #002F6C !important; color: #ffffff !important; }
.act-btn-deactivate { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.act-btn-deactivate:hover { background: #dc2626 !important; color: #ffffff !important; }
.act-btn-activate { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-activate:hover { background: #16a34a !important; color: #ffffff !important; }
.act-btn-viewreq { color: #d97706 !important; border-color: #fcd34d !important; background: #ffffff !important; }
.act-btn-viewreq:hover { background: #d97706 !important; color: #ffffff !important; }
.act-btn-approve { color: #16a34a !important; border-color: #86efac !important; background: #ffffff !important; }
.act-btn-approve:hover { background: #16a34a !important; color: #ffffff !important; }
.act-btn-reject { color: #dc2626 !important; border-color: #fca5a5 !important; background: #ffffff !important; }
.act-btn-reject:hover { background: #dc2626 !important; color: #ffffff !important; }
.act-btn-history { color: #6366f1 !important; border-color: #c7d2fe !important; background: #ffffff !important; }
.act-btn-history:hover { background: #6366f1 !important; color: #ffffff !important; }
.act-btn-batches { color: #0284c7 !important; border-color: #bae6fd !important; background: #ffffff !important; }
.act-btn-batches:hover { background: #0284c7 !important; color: #ffffff !important; }
.act-btn-wrap { display: flex; flex-direction: column; gap: 3px; width: 100%; align-items: center; }
.pricing-table th {
    background: #002F6C !important; 
    color: #ffffff !important; 
    padding: 10px 8px !important; 
    text-align: left;
    font-size: 11px !important; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: .4px; 
    border-bottom: 2px solid #001f48 !important; 
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
        <div class="table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
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
                            <strong style="font-family:monospace;color:#002F6C;font-size:14px;"><?php echo htmlspecialchars($f['ugt_no'] ?? ('UGT #' . $f['pump_id'])); ?></strong>
                            <div style="font-size:11px;color:#64748b;margin-top:2px;font-weight:600;"><?php echo htmlspecialchars($f['tank_label']); ?></div>
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
                                    <div style="display:flex; gap:4px; margin-bottom:4px;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="approve_price">
                                            <input type="hidden" name="approval_id" value="<?php echo $f['approval_id']; ?>">
                                            <input type="hidden" name="active_tab" value="fuel">
                                            <button type="submit" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;transition:all 0.2s;" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'"><i class="fas fa-check"></i> Approve</button>
                                        </form>
                                        <button type="button" class="btn" style="background:#fff;color:#475569;border:1px solid #cbd5e1;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;display:flex;align-items:center;gap:4px;transition:all 0.2s;" onclick="openRejectModal(<?php echo $f['approval_id']; ?>, 'fuel')" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8'" onmouseout="this.style.background='#fff';this.style.borderColor='#cbd5e1'"><i class="fas fa-times"></i> Reject</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <button onclick="openAdminEditFuelModal(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($canonical_type)); ?>', <?php echo (float)$f['price_per_liter']; ?>, <?php echo (float)$capacity; ?>, <?php echo (float)$critical; ?>)" class="act-btn act-btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </button>
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

    <!-- ── 1. Summary Cards ────────────────────────────────────────────────── -->
    <div class="summary-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 18px;">
        <div class="summary-card s-total">
            <i class="fas fa-box" style="font-size:22px;color:#002F6C;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo count($merch_all); ?></div>
            <div class="s-lbl">Total Products</div>
        </div>
        <div class="summary-card s-valid">
            <i class="fas fa-tags" style="font-size:22px;color:#16a34a;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $merch_stats['valid_price']; ?></div>
            <div class="s-lbl">Current Active Prices</div>
        </div>
        <div class="summary-card s-unpriced">
            <i class="fas fa-hourglass-half" style="font-size:22px;color:#d97706;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $pending_requests_count; ?></div>
            <div class="s-lbl">Pending Price Requests</div>
        </div>
        <div class="summary-card s-total">
            <i class="fas fa-check-double" style="font-size:22px;color:#16a34a;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $approved_today_count; ?></div>
            <div class="s-lbl">Approved Today</div>
        </div>
    </div>

    <!-- ── 2. Filters Toolbar ─────────────────────────────────────────────── -->
    <div class="toolbar" style="margin-bottom: 16px;">
        <input type="text" id="adminSearchInput" placeholder="&#128269; Search Product / SKU&hellip;" oninput="filterAdminMerchTable()" style="min-width: 220px;">
        
        <select id="adminCatFilter" onchange="filterAdminMerchTable()">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminBrandFilter" onchange="filterAdminMerchTable()">
            <option value="">All Brands</option>
            <?php foreach ($all_brands as $brand): ?>
                <option value="<?php echo htmlspecialchars($brand); ?>"><?php echo htmlspecialchars($brand); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminUnitFilter" onchange="filterAdminMerchTable()">
            <option value="">All UOMs</option>
            <?php foreach ($all_units as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminProdStatusFilter" onchange="filterAdminMerchTable()">
            <option value="">All Product Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>

        <select id="adminReqStatusFilter" onchange="filterAdminMerchTable()">
            <option value="">All Request Statuses</option>
            <option value="current">Current</option>
            <option value="pending">Pending Approval</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <!-- ── 3. Merchandise Table ────────────────────────────────────────────── -->
    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x:hidden; width:100%;">
            <table class="pricing-table" id="adminMerchTable" style="width:100%; table-layout:fixed;">
                <colgroup>
                    <col style="width:100px;">
                    <col style="width:auto;">
                    <col style="width:160px;">
                    <col style="width:90px;">
                    <col style="width:130px;">
                    <col style="width:90px;">
                    <col style="width:120px;">
                    <col style="width:100px;">
                    <col style="width:90px;">
                    <col style="width:180px;">
                </colgroup>
                <thead style="background:#002F6C !important;">
                    <tr style="background:#002F6C !important;">
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase;">SKU</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase;">Product</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase;">Category / Brand</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase;">UOM</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:right;">Default Selling Price</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Total Stock</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Request Status</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Product Status</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Updated</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminMerchBody">
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
                        $updated       = !empty($item['last_updated']) ? date('M d, Y', strtotime($item['last_updated'])) : '&mdash;';
                        $app_status    = strtolower(trim($item['approval_status'] ?? 'current'));
                        if (empty($app_status)) $app_status = 'current';

                        $prod_status   = strtolower(trim($item['status'] ?? 'active'));
                        $is_inactive   = in_array($prod_status, ['inactive', 'disabled', 'deactivated']);
                    ?>
                    <tr class="admin-merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($item['brand'] ?? 'Generic')); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($item['unit'] ?? 'pcs')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-prodstatus="<?php echo $is_inactive ? 'inactive' : 'active'; ?>"
                        data-reqstatus="<?php echo $app_status; ?>"
                        <?php if ($is_inactive): ?>style="opacity:0.6;background:#f8f9fa;"<?php endif; ?>>
                        
                        <!-- SKU -->
                        <td>
                            <code style="font-size:11px;color:#4f46e5;background:#ede9fe;padding:2px 6px;border-radius:4px;font-weight:700;">
                                <?php echo htmlspecialchars($item['sku'] ?? '—'); ?>
                            </code>
                        </td>

                        <!-- Product -->
                        <td>
                            <strong style="color:#1e293b;font-size:13px;"><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                        </td>

                        <!-- Category / Brand -->
                        <td>
                            <div style="font-weight:600;color:#334155;font-size:12px;"><?php echo htmlspecialchars($cat_label); ?></div>
                            <div class="muted" style="font-size:11px;"><?php echo htmlspecialchars($item['brand'] ?? 'Generic'); ?></div>
                        </td>

                        <!-- UOM -->
                        <td style="font-size:12px;color:#334155;font-weight:500;"><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>

                        <!-- Default Selling Price -->
                        <td style="text-align:right;">
                            <?php if ($price <= 0): ?>
                                <span class="badge badge-noprice">No Price Set</span>
                            <?php else: ?>
                                <strong style="color:#002F6C;font-size:13px;">&#8369;<?php echo number_format($price, 2); ?></strong>
                            <?php endif; ?>
                        </td>

                        <!-- Total Stock -->
                        <td style="text-align:center;">
                            <strong style="font-size:13px;color:#1e293b;"><?php echo number_format($stock, 0); ?></strong>
                        </td>

                        <!-- Request Status -->
                        <td style="text-align:center;">
                            <?php if ($app_status === 'pending'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700;"><i class="fas fa-clock" style="font-size:10px;margin-right:3px;"></i> Pending</span>
                            <?php elseif ($app_status === 'approved'): ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;font-weight:700;"><i class="fas fa-check-circle" style="font-size:10px;margin-right:3px;"></i> Approved</span>
                            <?php elseif ($app_status === 'rejected'): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;font-weight:700;"><i class="fas fa-times-circle" style="font-size:10px;margin-right:3px;"></i> Rejected</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-weight:600;"><i class="fas fa-check" style="font-size:9px;color:#16a34a;margin-right:3px;"></i> Current</span>
                            <?php endif; ?>
                        </td>

                        <!-- Product Status -->
                        <td style="text-align:center;">
                            <?php if ($is_inactive): ?>
                                <span class="badge badge-out">Inactive</span>
                            <?php else: ?>
                                <span class="badge badge-available">Active</span>
                            <?php endif; ?>
                        </td>

                        <!-- Updated -->
                        <td style="text-align:center;font-size:11px;color:#64748b;"><?php echo $updated; ?></td>

                        <!-- Actions -->
                        <td style="text-align:center;">
                            <div class="act-btn-wrap">
                                <?php if ($app_status === 'pending'): ?>
                                    <button onclick="openViewRequestModal(<?php echo $item['approval_id']; ?>)" class="act-btn act-btn-viewreq">
                                        <i class="fas fa-eye"></i> View Request
                                    </button>
                                    <button onclick="openApproveConfirmModal(<?php echo $item['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', '<?php echo number_format($price, 2); ?>', '<?php echo number_format($item['pending_price'], 2); ?>')" class="act-btn act-btn-approve">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button onclick="openRejectReasonModal(<?php echo $item['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>')" class="act-btn act-btn-reject">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php else: ?>
                                    <button onclick="openAdminEditProductModal(<?php echo $item['id']; ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="openPriceHistoryModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>')" class="act-btn act-btn-history">
                                        <i class="fas fa-history"></i> Price History
                                    </button>
                                    <button onclick="viewAdminBatches(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>')" class="act-btn act-btn-batches">
                                        <i class="fas fa-layer-group"></i> View Batches
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
            <div class="table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
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
                                    <div style="display:flex;gap:4px;justify-content:center;margin-bottom:4px;">
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
                                <?php endif; ?>
                                <button onclick="openAdminEditServiceModal(<?php echo $svc['id']; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', '<?php echo htmlspecialchars(addslashes($svc['category']??'')); ?>', '<?php echo htmlspecialchars(addslashes($svc['service_key']??'')); ?>', <?php echo (float)$currentPrice; ?>, <?php echo (int)($svc['active']??1); ?>)" class="act-btn act-btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
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

<!-- ══════════════════════════════════════════════════════════════════════════
     ADMIN MODALS & JAVASCRIPT LOGIC
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- 1. ADMIN EDIT PRODUCT MODAL (Price read-only) -->
<div id="adminEditProductModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:550px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-edit"></i> Edit Product Details</h4>
            <button onclick="closeAdminEditProductModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <form id="adminEditProductForm" style="padding:20px;">
            <input type="hidden" id="adminEditId">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div style="grid-column: span 2;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Product Name</label>
                    <input type="text" id="adminEditName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Category</label>
                    <input type="text" id="adminEditCategory" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Brand</label>
                    <input type="text" id="adminEditBrand" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">SKU / Product Code</label>
                    <input type="text" id="adminEditSku" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Unit of Measure (UOM)</label>
                    <input type="text" id="adminEditUnit" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Reorder Level</label>
                    <input type="number" id="adminEditReorder" min="1" value="24" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Critical Level</label>
                    <input type="number" id="adminEditCritical" min="1" value="10" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Product Status</label>
                    <select id="adminEditStatus" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Default Selling Price (₱)</label>
                    <input type="number" step="0.01" min="0" id="adminEditPrice" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C;" placeholder="0.00">
                </div>
            </div>
            <div style="margin-top:10px; font-size:11px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:8px 10px; border-radius:6px;">
                <i class="fas fa-shield-alt"></i> <em>As Admin, any price edit you save will take effect immediately.</em>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditProductModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; color:#475569; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1.1 ADMIN EDIT FUEL MODAL -->
<div id="adminEditFuelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-gas-pump"></i> Edit Fuel Product (Admin)</h4>
            <button onclick="closeAdminEditFuelModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <form id="adminEditFuelForm" style="padding:20px;">
            <input type="hidden" id="adminEditFuelId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px;">Fuel Type</label>
                <div id="adminEditFuelTypeDisplay" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; background:#f8fafc; color:#1e293b; font-weight:700; border-radius:6px; font-size:13px;"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Price / Liter (₱)</label>
                    <input type="number" step="0.01" min="0" id="adminEditFuelPrice" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C;" placeholder="0.00">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Capacity (L)</label>
                    <input type="number" step="0.01" min="0" id="adminEditFuelCapacity" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" placeholder="0.00">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Critical Level (L)</label>
                <input type="number" step="0.01" min="0" id="adminEditFuelCritical" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" placeholder="0.00">
            </div>
            <div style="margin-top:10px; font-size:11px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:8px 10px; border-radius:6px;">
                <i class="fas fa-shield-alt"></i> <em>As Admin, saving this edit will update live fuel pricing immediately.</em>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditFuelModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; color:#475569; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1.2 ADMIN EDIT SERVICE MODAL -->
<div id="adminEditServiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-wrench"></i> Edit Service Type (Admin)</h4>
            <button onclick="closeAdminEditServiceModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <form id="adminEditServiceForm" style="padding:20px;">
            <input type="hidden" id="adminEditServiceId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Service Name</label>
                <input type="text" id="adminEditServiceName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Category</label>
                    <input type="text" id="adminEditServiceCategory" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Service Key</label>
                    <input type="text" id="adminEditServiceKey" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Service Price (₱)</label>
                    <input type="number" step="0.01" min="0" id="adminEditServicePrice" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C;" placeholder="0.00">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Status</label>
                    <select id="adminEditServiceActive" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:10px; font-size:11px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:8px 10px; border-radius:6px;">
                <i class="fas fa-shield-alt"></i> <em>As Admin, saving this edit will update service pricing immediately.</em>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditServiceModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; color:#475569; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. VIEW REQUEST MODAL -->
<div id="viewRequestModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-file-invoice-dollar"></i> PRICE CHANGE REQUEST</h4>
            <button onclick="closeViewRequestModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <div id="viewRequestContent" style="padding:20px;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>

<!-- 3. APPROVE CONFIRMATION MODAL -->
<div id="approveConfirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:440px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#16a34a; color:#fff; padding:16px 20px;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-check-circle"></i> Confirm Approval</h4>
        </div>
        <div style="padding:20px;">
            <p style="font-size:14px; color:#1e293b; margin-top:0;">Approve this price change?</p>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:14px; border-radius:8px; margin-bottom:20px;">
                <div id="appModalProdName" style="font-weight:700; color:#166534; font-size:14px; margin-bottom:6px;"></div>
                <div style="font-size:13px; color:#334155; display:flex; justify-content:space-between;">
                    <span>Old Price: <strong id="appModalOldPrice" style="color:#64748b;"></strong></span>
                    <span>→</span>
                    <span>New Price: <strong id="appModalNewPrice" style="color:#16a34a;"></strong></span>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <input type="hidden" id="confirmApproveId">
                <button type="button" onclick="closeApproveConfirmModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; color:#475569; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="button" onclick="confirmApprovePriceRequest()" style="padding:8px 18px; border:none; background:#16a34a; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;">✔ Approve Request</button>
            </div>
        </div>
    </div>
</div>

<!-- 4. REJECT REASON MODAL -->
<div id="rejectReasonModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:440px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#dc2626; color:#fff; padding:16px 20px;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-times-circle"></i> Reject Price Change</h4>
        </div>
        <div style="padding:20px;">
            <input type="hidden" id="rejectReasonApprovalId">
            <p id="rejectModalProdName" style="font-size:14px; font-weight:600; color:#1e293b; margin-top:0;"></p>
            <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px;">Rejection Reason</label>
            <textarea id="rejectReasonText" rows="3" required placeholder="Enter reason for rejecting this price request&hellip;" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;"></textarea>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeRejectReasonModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; color:#475569; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="button" onclick="confirmRejectPriceRequest()" style="padding:8px 18px; border:none; background:#dc2626; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;">❌ Reject Request</button>
            </div>
        </div>
    </div>
</div>

<!-- 5. VIEW PRICE HISTORY MODAL -->
<div id="priceHistoryModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:650px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 id="priceHistoryTitle" style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-history"></i> Price History</h4>
            <button onclick="closePriceHistoryModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <div id="priceHistoryContent" style="padding:20px; max-height:450px; overflow-y:auto;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>

<!-- 6. VIEW BATCHES MODAL (ADMIN) -->
<div id="viewAdminBatchesModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:700px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 id="adminBatchesTitle" style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-layer-group"></i> Batch History</h4>
            <button onclick="closeAdminBatchesModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <div id="adminBatchesContent" style="padding:20px; max-height:450px; overflow-y:auto;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>


<script>
// ── Admin Merchandise Filter Function ──────────────────────────────────────
function filterAdminMerchTable() {
    var q          = (document.getElementById('adminSearchInput').value || '').toLowerCase().trim();
    var catFilter  = document.getElementById('adminCatFilter').value;
    var brandFilter= (document.getElementById('adminBrandFilter').value || '').toLowerCase();
    var unitFilter = (document.getElementById('adminUnitFilter').value || '').toLowerCase();
    var pStFilter  = document.getElementById('adminProdStatusFilter').value;
    var rStFilter  = document.getElementById('adminReqStatusFilter').value;

    var rows       = document.querySelectorAll('#adminMerchBody .admin-merch-row');
    var catHeaders = document.querySelectorAll('#adminMerchBody .cat-row');
    var catVisibleCount = {};

    rows.forEach(function(row) {
        var name     = row.getAttribute('data-name') || '';
        var sku      = row.getAttribute('data-sku')  || '';
        var brand    = row.getAttribute('data-brand') || '';
        var unit     = row.getAttribute('data-unit')  || '';
        var cat      = row.getAttribute('data-cat')   || '';
        var pStatus  = row.getAttribute('data-prodstatus') || '';
        var rStatus  = row.getAttribute('data-reqstatus')  || '';

        var matchQ      = !q || name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || brand.indexOf(q) !== -1;
        var matchCat    = !catFilter || cat === catFilter;
        var matchBrand  = !brandFilter || brand === brandFilter;
        var matchUnit   = !unitFilter || unit === unitFilter;
        var matchPStatus= !pStFilter || pStatus === pStFilter;
        var matchRStatus= !rStFilter || rStatus === rStFilter;

        var show = matchQ && matchCat && matchBrand && matchUnit && matchPStatus && matchRStatus;
        row.style.display = show ? '' : 'none';
        if (show) {
            catVisibleCount[cat] = (catVisibleCount[cat] || 0) + 1;
        }
    });

    catHeaders.forEach(function(hdr) {
        var cat = hdr.getAttribute('data-cat-header') || '';
        var count = catVisibleCount[cat] || 0;
        hdr.style.display = count > 0 ? '' : 'none';
    });
}

// ── 1. Admin Edit Product Modal ────────────────────────────────────────────
function openAdminEditProductModal(id) {
    document.getElementById('adminEditId').value = id;
    document.getElementById('adminEditProductModal').style.display = 'flex';
    fetch('admin_set_prices_handler.php?action=get_merch_details_admin&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.item) {
                var i = data.item;
                document.getElementById('adminEditName').value     = i.product_name || '';
                document.getElementById('adminEditCategory').value = i.category || '';
                document.getElementById('adminEditBrand').value    = i.brand || '';
                document.getElementById('adminEditSku').value      = i.sku || '';
                document.getElementById('adminEditUnit').value     = i.unit || 'pcs';
                document.getElementById('adminEditReorder').value  = parseInt(i.reorder_level || 24);
                document.getElementById('adminEditCritical').value = parseInt(i.critical_level || 10);
                document.getElementById('adminEditStatus').value   = i.status || 'active';
                document.getElementById('adminEditPrice').value    = parseFloat(i.unit_price || 0).toFixed(2);
            }
        });
}

function closeAdminEditProductModal() {
    document.getElementById('adminEditProductModal').style.display = 'none';
    document.getElementById('adminEditProductForm').reset();
}

document.getElementById('adminEditProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',         'edit_product_admin');
    fd.append('id',             document.getElementById('adminEditId').value);
    fd.append('product_name',   document.getElementById('adminEditName').value.trim());
    fd.append('category',       document.getElementById('adminEditCategory').value.trim());
    fd.append('brand',          document.getElementById('adminEditBrand').value.trim());
    fd.append('sku',            document.getElementById('adminEditSku').value.trim());
    fd.append('unit',           document.getElementById('adminEditUnit').value.trim());
    fd.append('unit_price',     document.getElementById('adminEditPrice').value);
    fd.append('reorder_level',  document.getElementById('adminEditReorder').value);
    fd.append('critical_level', document.getElementById('adminEditCritical').value);
    fd.append('status',         document.getElementById('adminEditStatus').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                alert('SUCCESS: Product updated!');
                closeAdminEditProductModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to update product'));
            }
        }).catch(() => alert('Error updating product.'));
});

// ── 1.1 Admin Edit Fuel Modal ─────────────────────────────────────────────
function openAdminEditFuelModal(id, fuelType, price, capacity, critical) {
    document.getElementById('adminEditFuelId').value = id;
    document.getElementById('adminEditFuelTypeDisplay').textContent = fuelType;
    document.getElementById('adminEditFuelPrice').value = parseFloat(price || 0).toFixed(2);
    document.getElementById('adminEditFuelCapacity').value = parseFloat(capacity || 0).toFixed(2);
    document.getElementById('adminEditFuelCritical').value = parseFloat(critical || 0).toFixed(2);
    document.getElementById('adminEditFuelModal').style.display = 'flex';
}

function closeAdminEditFuelModal() {
    document.getElementById('adminEditFuelModal').style.display = 'none';
    document.getElementById('adminEditFuelForm').reset();
}

document.getElementById('adminEditFuelForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',         'admin_edit_fuel');
    fd.append('id',             document.getElementById('adminEditFuelId').value);
    fd.append('price',          document.getElementById('adminEditFuelPrice').value);
    fd.append('capacity',       document.getElementById('adminEditFuelCapacity').value);
    fd.append('critical_level', document.getElementById('adminEditFuelCritical').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                alert('SUCCESS: Fuel product updated!');
                closeAdminEditFuelModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to update fuel product'));
            }
        }).catch(() => alert('Error updating fuel product.'));
});

// ── 1.2 Admin Edit Service Modal ──────────────────────────────────────────
function openAdminEditServiceModal(id, name, category, key, price, active) {
    document.getElementById('adminEditServiceId').value = id;
    document.getElementById('adminEditServiceName').value = name;
    document.getElementById('adminEditServiceCategory').value = category;
    document.getElementById('adminEditServiceKey').value = key;
    document.getElementById('adminEditServicePrice').value = parseFloat(price || 0).toFixed(2);
    document.getElementById('adminEditServiceActive').value = active ? '1' : '0';
    document.getElementById('adminEditServiceModal').style.display = 'flex';
}

function closeAdminEditServiceModal() {
    document.getElementById('adminEditServiceModal').style.display = 'none';
    document.getElementById('adminEditServiceForm').reset();
}

document.getElementById('adminEditServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',        'admin_edit_service');
    fd.append('id',            document.getElementById('adminEditServiceId').value);
    fd.append('service_name',  document.getElementById('adminEditServiceName').value.trim());
    fd.append('category',      document.getElementById('adminEditServiceCategory').value.trim());
    fd.append('service_key',   document.getElementById('adminEditServiceKey').value.trim());
    fd.append('service_price', document.getElementById('adminEditServicePrice').value);
    fd.append('active',        document.getElementById('adminEditServiceActive').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                alert('SUCCESS: Service type updated!');
                closeAdminEditServiceModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to update service type'));
            }
        }).catch(() => alert('Error updating service type.'));
});

// ── 2. View Request Modal ──────────────────────────────────────────────────
function openViewRequestModal(approvalId) {
    document.getElementById('viewRequestContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading details&hellip;</div>';
    document.getElementById('viewRequestModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_request_details&approval_id=' + approvalId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.request) {
                document.getElementById('viewRequestContent').innerHTML = '<div style="color:#dc2626;text-align:center;">Failed to load request details.</div>';
                return;
            }
            var req = data.request;
            var oldP = parseFloat(req.old_price || req.old_value || 0).toFixed(2);
            var newP = parseFloat(req.new_price || req.new_value || 0).toFixed(2);
            var reqBy = req.requested_by_name || 'Manager';
            var reason = req.reason || req.remarks || 'Supplier acquisition cost change';
            var dateReq = (req.created_at || '').substring(0, 16);

            document.getElementById('viewRequestContent').innerHTML = `
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Product:</td><td style="padding:8px 0; font-weight:700; color:#002F6C; text-align:right;">${req.product_name}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Current Price:</td><td style="padding:8px 0; font-weight:600; text-align:right; color:#64748b;">₱${oldP}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Requested Price:</td><td style="padding:8px 0; font-weight:700; text-align:right; color:#16a34a; font-size:15px;">₱${newP}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Requested By:</td><td style="padding:8px 0; text-align:right; font-weight:600;">${reqBy}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Reason:</td><td style="padding:8px 0; text-align:right; color:#334155;">${reason}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Date Requested:</td><td style="padding:8px 0; text-align:right; color:#64748b;">${dateReq}</td></tr>
                </table>
                <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                    <button onclick="closeViewRequestModal(); openApproveConfirmModal(${approvalId}, '${req.product_name.replace(/'/g, "\\'")}', '${oldP}', '${newP}')" style="padding:8px 18px; border:none; background:#16a34a; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-check"></i> Approve</button>
                    <button onclick="closeViewRequestModal(); openRejectReasonModal(${approvalId}, '${req.product_name.replace(/'/g, "\\'")}')" style="padding:8px 18px; border:none; background:#dc2626; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-times"></i> Reject</button>
                </div>
            `;
        });
}

function closeViewRequestModal() {
    document.getElementById('viewRequestModal').style.display = 'none';
}

// ── 3. Approve Confirmation Modal ──────────────────────────────────────────
function openApproveConfirmModal(approvalId, productName, currentPrice, requestedPrice) {
    document.getElementById('confirmApproveId').value = approvalId;
    document.getElementById('appModalProdName').textContent = productName;
    document.getElementById('appModalOldPrice').textContent = '₱' + currentPrice;
    document.getElementById('appModalNewPrice').textContent = '₱' + requestedPrice;
    document.getElementById('approveConfirmModal').style.display = 'flex';
}

function closeApproveConfirmModal() {
    document.getElementById('approveConfirmModal').style.display = 'none';
}

function confirmApprovePriceRequest() {
    var id = document.getElementById('confirmApproveId').value;
    var fd = new FormData();
    fd.append('action', 'approve_price_request');
    fd.append('approval_id', id);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                alert('✔ Price change approved!');
                closeApproveConfirmModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to approve request'));
            }
        }).catch(() => alert('Error approving request.'));
}

// ── 4. Reject Reason Modal ────────────────────────────────────────────────
function openRejectReasonModal(approvalId, productName) {
    document.getElementById('rejectReasonApprovalId').value = approvalId;
    document.getElementById('rejectModalProdName').textContent = productName;
    document.getElementById('rejectReasonText').value = '';
    document.getElementById('rejectReasonModal').style.display = 'flex';
}

function closeRejectReasonModal() {
    document.getElementById('rejectReasonModal').style.display = 'none';
}

function confirmRejectPriceRequest() {
    var id = document.getElementById('rejectReasonApprovalId').value;
    var reason = document.getElementById('rejectReasonText').value.trim();

    if (!reason) {
        alert('Please enter a rejection reason.');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'reject_price_request');
    fd.append('approval_id', id);
    fd.append('rejection_reason', reason);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                alert('❌ Price change rejected.');
                closeRejectReasonModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to reject request'));
            }
        }).catch(() => alert('Error rejecting request.'));
}

// ── 5. View Price History Modal ───────────────────────────────────────────
function openPriceHistoryModal(productId, productName) {
    document.getElementById('priceHistoryTitle').textContent = '📜 ' + productName + ' — Price History';
    document.getElementById('priceHistoryContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading history&hellip;</div>';
    document.getElementById('priceHistoryModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_price_history&product_id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.history || data.history.length === 0) {
                document.getElementById('priceHistoryContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-history" style="font-size:32px;margin-bottom:10px;display:block;"></i>No price change history found for this product.</div>';
                return;
            }
            var rows = data.history.map(function(h) {
                var oldP = parseFloat(h.old_price || 0).toFixed(2);
                var newP = parseFloat(h.new_price || 0).toFixed(2);
                var dateStr = (h.date_approved || h.date_requested || '').substring(0, 10);
                var reqBy = h.requested_by || 'Manager';
                var appBy = h.approved_by || 'Admin';
                var statusBadge = h.status === 'approved'
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">Approved</span>'
                    : '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">Rejected</span>';

                return '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 10px; font-size:12px; color:#64748b;">' + dateStr + '</td>'
                    + '<td style="padding:8px 10px; text-align:right; color:#64748b;">₱' + oldP + '</td>'
                    + '<td style="padding:8px 10px; text-align:right; font-weight:700; color:#002F6C;">₱' + newP + '</td>'
                    + '<td style="padding:8px 10px; font-size:12px;">' + reqBy + '</td>'
                    + '<td style="padding:8px 10px; font-size:12px;">' + appBy + ' ' + statusBadge + '</td>'
                    + '</tr>';
            }).join('');

            document.getElementById('priceHistoryContent').innerHTML =
                '<table style="width:100%; border-collapse:collapse; font-size:13px;">'
                + '<thead><tr style="background:#002F6C; color:#fff;">'
                + '<th style="padding:8px 10px; text-align:left;">Date</th>'
                + '<th style="padding:8px 10px; text-align:right;">Old Price</th>'
                + '<th style="padding:8px 10px; text-align:right;">New Price</th>'
                + '<th style="padding:8px 10px; text-align:left;">Requested By</th>'
                + '<th style="padding:8px 10px; text-align:left;">Approved By</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });
}

function closePriceHistoryModal() {
    document.getElementById('priceHistoryModal').style.display = 'none';
}

// ── 6. View Batches Modal (Admin) ──────────────────────────────────────────
function viewAdminBatches(productId, productName) {
    document.getElementById('adminBatchesTitle').textContent = '📦 ' + productName + ' — Batch History';
    document.getElementById('adminBatchesContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches&hellip;</div>';
    document.getElementById('viewAdminBatchesModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_product_batches&id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches || data.batches.length === 0) {
                document.getElementById('adminBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>No batch records found for this product.<br><small>Record a delivery to create the first batch.</small></div>';
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
                    + '<td style="padding:8px 10px;text-align:right;font-weight:700;">' + parseInt(b.remaining_qty||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#64748b;">&#8369;' + parseFloat(b.unit_cost||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#002F6C;font-weight:600;">&#8369;' + parseFloat(b.selling_price||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;font-size:11px;color:#64748b;">' + (b.date_received||'—').substring(0,10) + '</td>'
                    + '</tr>';
            }).join('');

            document.getElementById('adminBatchesContent').innerHTML =
                '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
                + '<thead><tr style="background:#002F6C;color:#fff;">'
                + '<th style="padding:10px;text-align:left;">Batch</th>'
                + '<th style="padding:10px;text-align:right;">Remaining</th>'
                + '<th style="padding:10px;text-align:right;">Cost</th>'
                + '<th style="padding:10px;text-align:right;">Selling</th>'
                + '<th style="padding:10px;">Date</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });
}

function closeAdminBatchesModal() {
    document.getElementById('viewAdminBatchesModal').style.display = 'none';
}

// ── Tab Switching ─────────────────────────────────────────────────────────
function switchTab(tabName) {
    // Hide all tab panels
    document.querySelectorAll('.tab-panel').forEach(function(panel) {
        panel.classList.remove('active');
    });
    // Deactivate all tab buttons
    document.querySelectorAll('.ato-tab').forEach(function(btn) {
        btn.classList.remove('active');
    });
    // Activate the selected panel
    var panel = document.getElementById('tab-' + tabName);
    if (panel) panel.classList.add('active');
    // Activate the selected button
    var btn = document.getElementById('tab-btn-' + tabName);
    if (btn) btn.classList.add('active');
    // Update the hidden input so forms remember the active tab
    var hidden = document.getElementById('activeSection');
    if (hidden) hidden.value = tabName;
    // Update rejectActiveTab hidden input if present
    var rejectHidden = document.getElementById('rejectActiveTab');
    if (rejectHidden) rejectHidden.value = tabName;
}

// Initialise — ensure the active tab panel is shown on page load
(function() {
    var activeHidden = document.getElementById('activeSection');
    var activeTab = activeHidden ? activeHidden.value : 'fuel';
    if (!activeTab) activeTab = 'fuel';
    // Only switch if none are already active (PHP already sets active class)
    var anyActive = document.querySelector('.tab-panel.active');
    if (!anyActive) switchTab(activeTab);
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
