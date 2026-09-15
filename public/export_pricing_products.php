<?php
/**
 * Official Product & Pricing Management Report Exporter
 * Petron Station Management System
 * Supports Fuel Products, Merchandise, and Service Types across Print, PDF, Excel, and CSV.
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_login();

$me            = current_user();
$my_role       = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control: Only Manager, Supervisor, Admin, and Superadmin can export pricing and product reports
if (!in_array($my_role, ['manager', 'supervisor', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$tab    = strtolower(trim($_GET['tab'] ?? 'fuel'));
$format = strtolower(trim($_GET['format'] ?? 'excel'));

if (!in_array($tab, ['fuel', 'merch', 'services'], true)) {
    $tab = 'fuel';
}
if (!in_array($format, ['excel', 'csv', 'pdf', 'print'], true)) {
    $format = 'excel';
}

$today          = date('Y-m-d');
$now_formatted  = date('F j, Y h:i A');
$admin_name     = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: $me['username'];

// Fetch Station Name
$station_name = 'Petron Carmen';
$target_sid   = $my_station_id ?: 1;

if ($my_station_id) {
    $stn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stn_stmt->execute([$my_station_id]);
    $stn_row = $stn_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stn_row && !empty($stn_row['name'])) {
        $station_name = $stn_row['name'];
    }
}

// Filter query parameters
$filter_q        = strtolower(trim($_GET['q'] ?? ''));
$filter_status   = strtolower(trim($_GET['status'] ?? ''));
$filter_category = strtolower(trim($_GET['category'] ?? ''));
$filter_brand    = strtolower(trim($_GET['brand'] ?? ''));

// Canonical fuel name helper
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
            return 'Xtra UNL';
        }
        return $name;
    }
}

// ── 1. GATHER DATA PER TAB ──────────────────────────────────────────────────
$report_title = '';
$table_headers = [];
$export_rows = [];

if ($tab === 'fuel') {
    $report_title = 'FUEL PRODUCTS & PRICING REPORT';
    $table_headers = [
        ['label' => 'UGT NO.', 'width' => '12%', 'align' => 'left'],
        ['label' => 'FUEL TYPE', 'width' => '20%', 'align' => 'left'],
        ['label' => 'PRICE / LITER', 'width' => '13%', 'align' => 'right'],
        ['label' => 'CURRENT VOL (L)', 'width' => '14%', 'align' => 'right'],
        ['label' => 'CAPACITY (L)', 'width' => '13%', 'align' => 'right'],
        ['label' => 'REORDER LVL (L)', 'width' => '13%', 'align' => 'right'],
        ['label' => 'STATUS', 'width' => '15%', 'align' => 'center']
    ];

    $TANK_CONFIG_17 = get_tank_config((int)$target_sid);
    $fi_lookup = [];
    $fi_status_by_id = [];

    $s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $ugt_val  = strtolower(trim($row['ugt_no'] ?? ''));

        if (!isset($fi_lookup[$fuel_key])) $fi_lookup[$fuel_key] = $row;
        if ($ugt_val) {
            $fi_lookup[$ugt_val] = $row;
            $u_num = preg_replace('/[^0-9]/', '', $ugt_val);
            if ($u_num) {
                $fi_lookup['ugt_' . (int)$u_num] = $row;
                $fi_lookup['ugt #' . (int)$u_num] = $row;
            }
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

    foreach ($TANK_CONFIG_17 as $tc) {
        $ft_key = strtolower(trim($tc['fuel_type']));
        $tank_num = $tc['tanker_num'];
        $tank_ugt_raw = strtolower(trim($tc['tank'] ?? ''));
        $tank_ugt_num = preg_replace('/[^0-9]/', '', $tank_ugt_raw);

        $inv = null;
        if ($tank_ugt_raw && isset($fi_lookup[$tank_ugt_raw])) {
            $inv = $fi_lookup[$tank_ugt_raw];
        } elseif ($tank_ugt_num && isset($fi_lookup['ugt_' . (int)$tank_ugt_num])) {
            $inv = $fi_lookup['ugt_' . (int)$tank_ugt_num];
        } elseif ($tank_ugt_num && isset($fi_lookup['ugt #' . (int)$tank_ugt_num])) {
            $inv = $fi_lookup['ugt #' . (int)$tank_ugt_num];
        } elseif (isset($fi_lookup[$ft_key . '_tank_' . $tank_num])) {
            $inv = $fi_lookup[$ft_key . '_tank_' . $tank_num];
        } elseif (isset($fi_lookup[$ft_key . '_' . $tank_ugt_raw])) {
            $inv = $fi_lookup[$ft_key . '_' . $tank_ugt_raw];
        } elseif ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
            $cand = (strpos(strtolower($tc['label']), '1') !== false) ? 'xtra unl 1' : 'xtra unl 2';
            $inv = $fi_lookup[$cand] ?? ($fi_lookup['xtra unl'] ?? null);
        } elseif ($ft_key === 'diesel') {
            $cand = (strpos(strtolower($tc['label']), '1') !== false) ? 'diesel 1' : 'diesel 2';
            $inv = $fi_lookup[$cand] ?? ($fi_lookup['diesel'] ?? null);
        } else {
            $inv = $fi_lookup[$ft_key] ?? null;
        }

        $capacity  = (float)$tc['capacity'];
        $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
        $tank_key  = strtolower(trim($tc['tank']));

        $deliv = $del_lookup[$tank_key] ?? ($del_lookup[$ft_key] ?? 0);
        $sales = $sales_lookup[$tank_key] ?? ($sales_lookup[$ft_key] ?? 0);
        $adj   = $adj_lookup[$tank_key] ?? ($adj_lookup[$ft_key] ?? 0);
        $ending_system = max(0, $cur_level + $deliv - $sales + $adj);

        $reorder_level = $inv ? (float)($inv['reorder_level'] ?? 0) : 0;
        if ($reorder_level <= 0) {
            $reorder_level = ($capacity == 14000) ? 5000 : (($capacity == 7000) ? 2000 : $capacity * 0.20);
        }

        $inv_id = $inv['id'] ?? null;
        $inv_status = $inv_id ? ($fi_status_by_id[(int)$inv_id] ?? 'active') : 'active';
        $is_deactivated = in_array($inv_status, ['inactive', 'disabled', 'deactivated'], true);

        if ($is_deactivated) {
            $status_str = 'Deactivated';
        } elseif ($ending_system <= 0) {
            $status_str = 'Out of Stock';
        } elseif ($reorder_level > 0 && $ending_system <= $reorder_level) {
            $status_str = 'Low Stock';
        } else {
            $status_str = 'Normal';
        }

        $price = ($inv && (float)($inv['price_per_liter'] ?? 0) > 0) ? (float)$inv['price_per_liter'] : ($price_lookup[$ft_key] ?? 0);
        $ugt_name = !empty($inv['ugt_no']) ? $inv['ugt_no'] : $tc['tank'];
        $fuel_name = !empty($inv['fuel_type']) ? $inv['fuel_type'] : $tc['fuel_type'];

        // Apply filters
        if ($filter_status !== '') {
            $st_norm = strtolower($status_str);
            if ($filter_status === 'active' && $is_deactivated) continue;
            if ($filter_status === 'inactive' && !$is_deactivated) continue;
            if ($filter_status === 'normal' && $st_norm !== 'normal') continue;
            if ($filter_status === 'low' || $filter_status === 'low stock') {
                if ($st_norm !== 'low stock' && $st_norm !== 'low') continue;
            }
            if ($filter_status === 'out' || $filter_status === 'out of stock') {
                if ($st_norm !== 'out of stock' && $st_norm !== 'out') continue;
            }
            if ($filter_status === 'deactivated' && $st_norm !== 'deactivated') continue;
        }

        if ($filter_q !== '') {
            $match = (strpos(strtolower($ugt_name), $filter_q) !== false) ||
                     (strpos(strtolower($fuel_name), $filter_q) !== false) ||
                     (strpos(strtolower($tc['label']), $filter_q) !== false);
            if (!$match) continue;
        }

        $export_rows[] = [
            'ugt_no'      => $ugt_name,
            'fuel_type'   => $fuel_name . ' (' . $tc['label'] . ')',
            'price'       => number_format($price, 2),
            'current_vol' => number_format($ending_system, 2),
            'capacity'    => number_format($capacity, 2),
            'reorder_lvl' => number_format($reorder_level, 2),
            'status'      => $status_str,
            '_is_active'  => !$is_deactivated
        ];
    }
} elseif ($tab === 'merch') {
    $report_title = 'MERCHANDISE PRODUCTS & PRICING REPORT';
    $table_headers = [
        ['label' => 'SKU / CODE', 'width' => '11%', 'align' => 'left'],
        ['label' => 'PRODUCT NAME', 'width' => '22%', 'align' => 'left'],
        ['label' => 'CATEGORY', 'width' => '12%', 'align' => 'left'],
        ['label' => 'BRAND', 'width' => '10%', 'align' => 'left'],
        ['label' => 'UOM', 'width' => '7%', 'align' => 'center'],
        ['label' => 'UNIT COST', 'width' => '10%', 'align' => 'right'],
        ['label' => 'SELLING PRICE', 'width' => '10%', 'align' => 'right'],
        ['label' => 'STOCK', 'width' => '6%', 'align' => 'right'],
        ['label' => 'REORDER', 'width' => '6%', 'align' => 'right'],
        ['label' => 'STATUS', 'width' => '10%', 'align' => 'center']
    ];

    $raw_rows = load_merchandise_pricing_catalog($pdo, (int)$target_sid);

    foreach ($raw_rows as $row) {
        $cat    = trim($row['category_name'] ?? $row['category'] ?? 'Uncategorized');
        $brand  = trim($row['brand'] ?? 'Petron Corporation');
        $cost   = (float)($row['unit_cost'] ?? 0);
        $price  = (float)($row['unit_price'] ?? 0);
        $stock  = (float)($row['stock_quantity'] ?? $row['stock'] ?? 0);
        $reorder= (float)($row['reorder_level'] ?? 24);
        $status_raw = strtolower(trim($row['status'] ?? 'active'));
        $is_inactive = in_array($status_raw, ['inactive', 'disabled', 'deactivated'], true);

        if ($is_inactive) {
            $status_label = 'Deactivated';
        } elseif ($price <= 0) {
            $status_label = 'No Price';
        } elseif ($stock <= 0) {
            $status_label = 'Out of Stock';
        } elseif ($reorder > 0 && $stock <= $reorder) {
            $status_label = 'Low Stock';
        } else {
            $status_label = 'Available';
        }

        // Apply filters
        if ($filter_category !== '' && strtolower($cat) !== $filter_category) {
            continue;
        }
        if ($filter_brand !== '' && strtolower($brand) !== $filter_brand) {
            continue;
        }
        if ($filter_status !== '') {
            $st_norm = strtolower($status_label);
            if ($filter_status === 'available' && $st_norm !== 'available') continue;
            if ($filter_status === 'low' || $filter_status === 'low stock') {
                if ($st_norm !== 'low stock') continue;
            }
            if ($filter_status === 'out' || $filter_status === 'out of stock') {
                if ($st_norm !== 'out of stock') continue;
            }
            if ($filter_status === 'inactive' || $filter_status === 'deactivated') {
                if ($st_norm !== 'deactivated') continue;
            }
            if ($filter_status === 'noprice' && $st_norm !== 'no price') continue;
            if ($filter_status === 'belowcost' && !($price > 0 && $price < $cost)) continue;
        }

        if ($filter_q !== '') {
            $sku = strtolower(trim($row['sku'] ?? ''));
            $name = strtolower(trim($row['product_name'] ?? $row['name'] ?? ''));
            $match = (strpos($sku, $filter_q) !== false) ||
                     (strpos($name, $filter_q) !== false) ||
                     (strpos(strtolower($cat), $filter_q) !== false) ||
                     (strpos(strtolower($brand), $filter_q) !== false);
            if (!$match) continue;
        }

        $export_rows[] = [
            'sku'          => trim($row['sku'] ?? '—') ?: '—',
            'name'         => trim($row['product_name'] ?? $row['name'] ?? 'Unknown Product'),
            'category'     => $cat,
            'brand'        => $brand,
            'unit'         => trim($row['unit'] ?? 'pcs'),
            'cost'         => number_format($cost, 2),
            'price'        => number_format($price, 2),
            'stock'        => number_format($stock, 0),
            'reorder'      => number_format($reorder, 0),
            'status'       => $status_label,
            '_is_active'   => !$is_inactive
        ];
    }
} elseif ($tab === 'services') {
    $report_title = 'SERVICE TYPES & PRICING REPORT';
    $table_headers = [
        ['label' => 'CODE', 'width' => '10%', 'align' => 'left'],
        ['label' => 'SERVICE NAME', 'width' => '28%', 'align' => 'left'],
        ['label' => 'CATEGORY', 'width' => '16%', 'align' => 'left'],
        ['label' => 'SERVICE FEE', 'width' => '12%', 'align' => 'right'],
        ['label' => 'LABOR FEE', 'width' => '12%', 'align' => 'right'],
        ['label' => 'TOTAL FEE', 'width' => '12%', 'align' => 'right'],
        ['label' => 'STATUS', 'width' => '10%', 'align' => 'center']
    ];

    $stmt = $pdo->prepare("
        SELECT id, service_code, service_name, category, service_price, labor_fee, estimated_duration, required_mechanics, active, updated_at
        FROM job_order_service_types
        ORDER BY category ASC, service_name ASC
    ");
    $stmt->execute();
    $raw_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_services as $svc) {
        $svc_id     = (int)$svc['id'];
        $svc_code   = trim($svc['service_code'] ?? '') ?: ('SVC-' . str_pad($svc_id, 4, '0', STR_PAD_LEFT));
        $svc_name   = trim($svc['service_name'] ?? '');
        $category   = trim($svc['category'] ?? 'Others') ?: 'Others';
        $svcFee     = (float)($svc['service_price'] ?? 0);
        $labFee     = (float)($svc['labor_fee'] ?? 0);
        $totalFee   = $svcFee + $labFee;
        $isActive   = (int)($svc['active'] ?? 1) === 1;
        $status_label = $isActive ? 'Active' : 'Inactive';

        // Apply filters
        if ($filter_category !== '' && strtolower($category) !== $filter_category) {
            continue;
        }
        if ($filter_status !== '') {
            if ($filter_status === '1' || $filter_status === 'active') {
                if (!$isActive) continue;
            } elseif ($filter_status === '0' || $filter_status === 'inactive') {
                if ($isActive) continue;
            }
        }
        if ($filter_q !== '') {
            $match = (strpos(strtolower($svc_code), $filter_q) !== false) ||
                     (strpos(strtolower($svc_name), $filter_q) !== false) ||
                     (strpos(strtolower($category), $filter_q) !== false);
            if (!$match) continue;
        }

        $export_rows[] = [
            'code'        => $svc_code,
            'name'        => $svc_name,
            'category'    => $category,
            'service_fee' => number_format($svcFee, 2),
            'labor_fee'   => number_format($labFee, 2),
            'total_fee'   => number_format($totalFee, 2),
            'status'      => $status_label,
            '_is_active'  => $isActive
        ];
    }
}

$record_count = count($export_rows);

// Audit Log for Export Action
try {
    log_activity(
        $pdo,
        $me['id'],
        'Exported Pricing Report',
        "Exported $record_count records (Tab: " . strtoupper($tab) . ", Format: " . strtoupper($format) . ", Station: $station_name)"
    );
} catch (Exception $e) { /* ignore */ }

// ── 2. FORMAT: EXCEL (.XLS) ────────────────────────────────────────────────
if ($format === 'excel') {
    $filename = "Petron_{$tab}_Pricing_Report_{$today}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $col_count = count($table_headers);

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    body { font-family: Arial, sans-serif; font-size: 11px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #000000; padding: 6px 10px; }
    th { background-color: #00264D; color: #ffffff; font-weight: bold; text-align: center; }
    .hdr-title { font-size: 16px; font-weight: bold; color: #00264D; text-align: center; text-transform: uppercase; border: none; }
    .hdr-station { font-size: 12px; font-weight: bold; color: #00264D; text-align: center; border: none; }
    .hdr-date { font-size: 11px; color: #475569; text-align: center; border: none; }
</style>
</head>
<body>
<table>
    <tr><td colspan="' . $col_count . '" align="center" class="hdr-title" style="text-align:center;font-size:16px;font-weight:bold;color:#00264D;border:none;padding:6px 0;">' . htmlspecialchars($report_title) . '</td></tr>
    <tr><td colspan="' . $col_count . '" align="center" class="hdr-station" style="text-align:center;font-size:12px;font-weight:bold;color:#00264D;border:none;padding:3px 0;">' . htmlspecialchars($station_name) . '</td></tr>
    <tr><td colspan="' . $col_count . '" align="center" class="hdr-date" style="text-align:center;font-size:11px;color:#475569;border:none;padding:3px 0 8px 0;">Date: ' . htmlspecialchars($now_formatted) . '</td></tr>
    <tr><td colspan="' . $col_count . '" style="border:none;"></td></tr>
    <tr>';

    foreach ($table_headers as $th) {
        echo '<th>' . htmlspecialchars($th['label']) . '</th>';
    }
    echo '</tr>';

    foreach ($export_rows as $row) {
        echo '<tr>';
        foreach ($row as $k => $v) {
            if ($k === '_is_active') continue;
            $align = (strpos($k, 'price') !== false || strpos($k, 'fee') !== false || strpos($k, 'cost') !== false || strpos($k, 'vol') !== false || strpos($k, 'capacity') !== false || strpos($k, 'reorder') !== false || strpos($k, 'stock') !== false) ? 'right' : ((strpos($k, 'status') !== false || strpos($k, 'unit') !== false || strpos($k, 'duration') !== false) ? 'center' : 'left');
            echo '<td align="' . $align . '" style="text-align:' . $align . ';">' . htmlspecialchars((string)$v) . '</td>';
        }
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
}

// ── 3. FORMAT: CSV (.CSV) ──────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = "Petron_{$tab}_Pricing_Report_{$today}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, [$report_title]);
    fputcsv($output, [$station_name]);
    fputcsv($output, ['Date: ' . $now_formatted]);
    fputcsv($output, []);

    $hdr_row = [];
    foreach ($table_headers as $th) {
        $hdr_row[] = $th['label'];
    }
    fputcsv($output, $hdr_row);

    foreach ($export_rows as $row) {
        $csv_line = [];
        foreach ($row as $k => $v) {
            if ($k === '_is_active') continue;
            $csv_line[] = $v;
        }
        fputcsv($output, $csv_line);
    }

    fclose($output);
    exit;
}

// ── 4. FORMAT: PDF & PRINT ──────────────────────────────────────────────────
$is_print_mode = ($format === 'print');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($report_title); ?> - Petron Station Management System</title>
<style>
    <?php if ($is_print_mode): ?>
    @page { size: A4 landscape; margin: 6mm; }
    body { font-family: Arial, sans-serif; font-size: 8px; color: #1e293b; margin: 0; padding: 0; background: #525659; display: flex; justify-content: center; }
    .rpt-paper-sheet { background: #ffffff; width: 100%; max-width: 1050px; margin: 25px auto; padding: 35px 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); border-radius: 4px; box-sizing: border-box; }
    @media print {
        body { background: #ffffff !important; padding: 0 !important; margin: 0 !important; display: block !important; }
        .rpt-paper-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: none !important; margin: 0 !important; border-radius: 0 !important; }
    }
    <?php else: ?>
    body { font-family: dejavusans, Arial, sans-serif; font-size: 8px; color: #1e293b; margin: 0; padding: 0; }
    .rpt-paper-sheet { width: 100%; }
    <?php endif; ?>
    .hdr-box { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #00264D; padding-bottom: 6px; }
    .hdr-box h2 { font-size: 14px; font-weight: bold; color: #00264D; text-transform: uppercase; margin: 0 0 2px 0; letter-spacing: 0.5px; }
    .hdr-box p { font-size: 9px; color: #475569; margin: 0 0 2px 0; font-weight: bold; }
    
    .data-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: fixed; }
    .data-tbl th { background: #00264D; color: #ffffff; padding: 5px 4px; font-size: 7.5pt; text-transform: uppercase; font-weight: bold; text-align: center; border: 1px solid #00264D; word-wrap: break-word; overflow-wrap: break-word; }
    .data-tbl td { padding: 4px 4px; font-size: 7.5pt; border: 1px solid #cbd5e1; vertical-align: middle; word-wrap: break-word; overflow-wrap: break-word; }
    .data-tbl tr:nth-child(even) td { background: #f8fafc; }
    
    .st-act { color: #15803d; font-weight: bold; }
    .st-inact { color: #b91c1c; font-weight: bold; }
    .st-warn { color: #d97706; font-weight: bold; }
</style>
</head>
<body>

<div class="rpt-paper-sheet">
<div class="hdr-box">
    <h2><?php echo htmlspecialchars($report_title); ?></h2>
    <p><?php echo htmlspecialchars($station_name); ?></p>
    <p style="font-weight: normal; color: #64748b; font-size: 8.5px;">Date: <?php echo htmlspecialchars($now_formatted); ?> &bull; Total Records: <?php echo $record_count; ?></p>
</div>

<table class="data-tbl">
    <thead>
        <tr>
            <?php foreach ($table_headers as $th): ?>
            <th style="width: <?php echo $th['width']; ?>; text-align: <?php echo $th['align']; ?>;"><?php echo htmlspecialchars($th['label']); ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($export_rows as $row): 
            $is_act = !empty($row['_is_active']);
            $status_val = $row['status'] ?? '';
            $status_class = 'st-act';
            if (in_array(strtolower($status_val), ['deactivated', 'inactive', 'out of stock'], true)) {
                $status_class = 'st-inact';
            } elseif (in_array(strtolower($status_val), ['low stock', 'no price'], true)) {
                $status_class = 'st-warn';
            }
        ?>
        <tr>
            <?php foreach ($row as $k => $v): 
                if ($k === '_is_active') continue;
                $align = (strpos($k, 'price') !== false || strpos($k, 'fee') !== false || strpos($k, 'cost') !== false || strpos($k, 'vol') !== false || strpos($k, 'capacity') !== false || strpos($k, 'reorder') !== false || strpos($k, 'stock') !== false) ? 'right' : ((strpos($k, 'status') !== false || strpos($k, 'unit') !== false || strpos($k, 'duration') !== false) ? 'center' : 'left');
            ?>
            <td style="text-align: <?php echo $align; ?>;">
                <?php if ($k === 'status'): ?>
                    <span class="<?php echo $status_class; ?>"><?php echo htmlspecialchars((string)$v); ?></span>
                <?php elseif ($k === 'ugt_no' || $k === 'sku' || $k === 'code'): ?>
                    <strong><?php echo htmlspecialchars((string)$v); ?></strong>
                <?php else: ?>
                    <?php echo htmlspecialchars((string)$v); ?>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($export_rows)): ?>
        <tr>
            <td colspan="<?php echo count($table_headers); ?>" style="text-align: center; padding: 15px; color: #94a3b8;">No records found matching criteria.</td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<table style="width: 100%; margin-top: 25px; border-collapse: collapse; page-break-inside: avoid;">
    <tr>
        <td style="width: 60%;"></td>
        <td style="width: 40%; vertical-align: bottom;">
            <div style="font-size: 10px; font-weight: bold; color: #00264D; text-transform: uppercase; text-align: left; margin-bottom: 30px;">PREPARED BY:</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="border-top: 1.5px solid #00264D; padding-top: 4px; text-align: center;">
                        <div style="font-size: 10px; font-weight: bold; color: #1e293b; text-transform: uppercase;"><?php echo htmlspecialchars($admin_name); ?></div>
                        <div style="font-size: 9px; color: #475569; font-weight: bold; margin-top: 1px;"><?php echo htmlspecialchars(ucfirst($my_role)); ?></div>
                        <div style="font-size: 8px; color: #64748b; margin-top: 2px;">Signature over Printed Name</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

if ($is_print_mode) {
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); }; window.onafterprint = function() { try { window.close(); } catch(e) {} };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    header("Content-Type: text/html; charset=utf-8");
    echo $html;
    exit;
}

try {
    $temp_dir = __DIR__ . '/../scratch';
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0777, true);
    }
    if (!is_writable($temp_dir)) {
        $temp_dir = sys_get_temp_dir();
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 10,
        'tempDir' => $temp_dir,
        'autoScriptToLang' => true,
        'autoLangToFont' => true
    ]);
    
    $mpdf->SetTitle($report_title . ' - ' . $station_name);
    $mpdf->SetAuthor('Petron Station Management System');
    $mpdf->WriteHTML($html);
    
    $ts_now = date('Y-m-d_His');
    $pdf_filename = "Petron_{$tab}_Pricing_Report_{$ts_now}.pdf";
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    $mpdf->Output($pdf_filename, 'D');
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    $print_script = "<script>window.onload = function() { window.focus(); window.print(); };</script>";
    $html = str_replace("</body>", $print_script . "</body>", $html);
    echo $html;
}
exit;