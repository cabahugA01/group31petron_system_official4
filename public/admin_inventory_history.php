<?php
// ============================================================
// Admin Inventory History Oversight - admin_inventory_history.php
// Rebuilt to support 7 required tabs, advanced ledger view,
// unified table format, custom PDF/print output, and centered details modals.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_inventory_history';
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
if (!in_array($role, ['admin','manager','superadmin'])) { 
    header('Location: dashboard.php'); 
    exit; 
}
if ($station_id <= 0 && $role === 'admin') { 
    render_no_station_page('admin_dashboard.php'); 
}

// ── User filters input ────────────────────────────────────────
$start_date = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end']   ?? date('Y-m-d');
$active_tab = $_GET['tab']   ?? 'merch';
$allowed_tabs = ['merch', 'fuel', 'requests', 'orders', 'deliveries', 'stock_in', 'adjustments'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'merch';
}

$search      = trim($_GET['search'] ?? '');
$ref_no      = trim($_GET['ref_no'] ?? '');
$category    = trim($_GET['category'] ?? '');
$move_type   = trim($_GET['move_type'] ?? '');
$perf_by     = trim($_GET['perf_by'] ?? '');
$status      = trim($_GET['status'] ?? '');

// ── DYNAMIC UNION SQL BUILDER ─────────────────────────────────
$union_sql = "";
$params = [];

if ($active_tab === 'merch') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            msi.encoded_at AS date_time,
            msi.po_number AS reference_no,
            'Merchandise' AS module,
            'Delivery' AS transaction_type,
            msi.product_name AS product_fuel,
            msi.qty_received AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            '—' AS approved_by,
            'Completed' AS status,
            msi.sku AS sku,
            msi.category AS category,
            msi.stock_before AS prev_qty,
            msi.stock_after AS new_qty,
            msi.remarks AS remarks,
            CONCAT('DEL-', msi.id) AS extra_1,
            '' AS extra_2,
            msi.encoded_by AS performed_by_id,
            0 AS approved_by_id
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        WHERE msi.station_id = ?

        UNION ALL

        SELECT
            mt.transaction_date AS date_time,
            mt.transaction_id AS reference_no,
            'Merchandise' AS module,
            'Sale' AS transaction_type,
            mti.product_name AS product_fuel,
            -mti.quantity AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u2.first_name, ' ', u2.last_name), ' '), u2.username, '—') AS approved_by,
            mt.validation_status AS status,
            ip.sku AS sku,
            mti.category AS category,
            0 AS prev_qty,
            0 AS new_qty,
            COALESCE(mt.remarks, mt.staff_remarks) AS remarks,
            CONCAT('SALE-', mt.transaction_id, '-', mti.id) AS extra_1,
            '' AS extra_2,
            mt.staff_id AS performed_by_id,
            COALESCE(mt.manager_id, 0) AS approved_by_id
        FROM merchandise_transaction_items mti
        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
        LEFT JOIN inventory_products ip ON mti.product_id = ip.id
        LEFT JOIN users u ON mt.staff_id = u.id
        LEFT JOIN users u2 ON mt.manager_id = u2.id
        WHERE mt.station_id = ? AND mt.validation_status IN ('Official','Completed','Approved','Adjusted') AND mti.item_type = 'merchandise'

        UNION ALL

        SELECT
            il.created_at AS date_time,
            '—' AS reference_no,
            'Merchandise' AS module,
            CASE 
                WHEN il.notes LIKE '%damaged%' THEN 'Damaged Item'
                WHEN il.notes LIKE '%variance%' OR il.notes LIKE '%correction%' OR il.notes LIKE '%count%' THEN 'Stock Correction'
                ELSE 'Adjustment'
            END AS transaction_type,
            ip.product_name AS product_fuel,
            il.quantity_change AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            '—' AS approved_by,
            'Approved' AS status,
            ip.sku AS sku,
            ip.category AS category,
            COALESCE(il.quantity_before, 0) AS prev_qty,
            COALESCE(il.quantity_after, 0) AS new_qty,
            il.notes AS remarks,
            CONCAT('ADJ-', il.id) AS extra_1,
            '' AS extra_2,
            il.user_id AS performed_by_id,
            0 AS approved_by_id
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.station_id = ? AND il.action = 'adjustment'
    ) AS merch_ledger
    ";
    $params = [$station_id, $station_id, $station_id];
} elseif ($active_tab === 'fuel') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            fd.created_at AS date_time,
            fd.invoice_no AS reference_no,
            'Fuel' AS module,
            'Delivery' AS transaction_type,
            fd.fuel_type AS product_fuel,
            fd.delivery_liters AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_val.first_name, ' ', u_val.last_name), ' '), u_val.username, '—') AS approved_by,
            fd.status AS status,
            '' AS sku,
            'Fuel' AS category,
            0 AS prev_qty,
            0 AS new_qty,
            fd.notes AS remarks,
            fd.tank_assigned AS extra_1,
            CONCAT('DEL-', fd.id) AS extra_2,
            fd.received_by AS performed_by_id,
            COALESCE(fd.verified_by, 0) AS approved_by_id
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users u_val ON fd.verified_by = u_val.id
        WHERE fd.station_id = ? AND fd.status IN ('Verified','Approved')

        UNION ALL

        SELECT
            ft.transaction_date AS date_time,
            ft.transaction_id AS reference_no,
            'Fuel' AS module,
            'Dispensed' AS transaction_type,
            ft.fuel_type AS product_fuel,
            -ft.liters_sold AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            '—' AS approved_by,
            ft.status AS status,
            '' AS sku,
            'Fuel' AS category,
            0 AS prev_qty,
            0 AS new_qty,
            ft.notes AS remarks,
            CONCAT('Pump #', IFNULL(ft.pump_id,'—')) AS extra_1,
            ft.transaction_id AS extra_2,
            ft.staff_id AS performed_by_id,
            0 AS approved_by_id
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.station_id = ? AND ft.status IN ('Approved','approved','Completed')

        UNION ALL

        SELECT
            fa.created_at AS date_time,
            '—' AS reference_no,
            'Fuel' AS module,
            CASE 
                WHEN fa.adjustment_type = 'variance' OR fa.reason LIKE '%variance%' OR fa.notes LIKE '%variance%' THEN 'Fuel Variance'
                WHEN fa.adjustment_type = 'correction' OR fa.reason LIKE '%correction%' OR fa.notes LIKE '%correction%' OR fa.notes LIKE '%count%' THEN 'Stock Correction'
                ELSE 'Adjustment'
            END AS transaction_type,
            fa.fuel_type AS product_fuel,
            fa.liters AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_app.first_name, ' ', u_app.last_name), ' '), u_app.username, '—') AS approved_by,
            fa.status AS status,
            '' AS sku,
            'Fuel' AS category,
            COALESCE(fa.previous_value, 0) AS prev_qty,
            COALESCE(fa.new_value, 0) AS new_qty,
            fa.reason AS remarks,
            '' AS extra_1,
            CONCAT('ADJ-', fa.id) AS extra_2,
            fa.user_id AS performed_by_id,
            COALESCE(fa.approved_by, 0) AS approved_by_id
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        LEFT JOIN users u_app ON fa.approved_by = u_app.id
        WHERE fa.station_id = ? AND fa.status = 'Approved'
    ) AS fuel_ledger
    ";
    $params = [$station_id, $station_id, $station_id];
} elseif ($active_tab === 'requests') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            sr.created_at AS date_time,
            CONCAT('PR-', sr.id) AS reference_no,
            'Purchase Requests' AS module,
            'Merchandise Request' AS transaction_type,
            sr.item_name AS product_fuel,
            sr.requested_quantity AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_mgr.first_name, ' ', u_mgr.last_name), ' '), u_mgr.username, '—') AS approved_by,
            sr.status AS status,
            sr.item_sku AS sku,
            sr.item_category AS category,
            sr.current_stock AS prev_qty,
            sr.approved_quantity AS new_qty,
            sr.remarks AS remarks,
            sr.manager_notes AS extra_1,
            COALESCE(sr.approved_price, 0) AS extra_2,
            sr.staff_id AS performed_by_id,
            COALESCE(sr.manager_id, 0) AS approved_by_id
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users u_mgr ON sr.manager_id = u_mgr.id
        WHERE sr.station_id = ?

        UNION ALL

        SELECT
            fsr.created_at AS date_time,
            CONCAT('PRF-', fsr.id) AS reference_no,
            'Purchase Requests' AS module,
            'Fuel Request' AS transaction_type,
            fsr.fuel_type AS product_fuel,
            fsr.requested_liters AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_mgr.first_name, ' ', u_mgr.last_name), ' '), u_mgr.username, '—') AS approved_by,
            fsr.status AS status,
            '' AS sku,
            'Fuel' AS category,
            fsr.current_level AS prev_qty,
            fsr.approved_liters AS new_qty,
            fsr.remarks AS remarks,
            fsr.manager_notes AS extra_1,
            '' AS extra_2,
            fsr.staff_id AS performed_by_id,
            COALESCE(fsr.manager_id, 0) AS approved_by_id
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users u_mgr ON fsr.manager_id = u_mgr.id
        WHERE fsr.station_id = ?
    ) AS requests_ledger
    ";
    $params = [$station_id, $station_id];
} elseif ($active_tab === 'orders') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            po.created_at AS date_time,
            po.po_number AS reference_no,
            'Purchase Orders' AS module,
            'Merchandise PO' AS transaction_type,
            po.product_name AS product_fuel,
            po.quantity AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u_mgr.first_name, ' ', u_mgr.last_name), ' '), u_mgr.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_adm.first_name, ' ', u_adm.last_name), ' '), u_adm.username, '—') AS approved_by,
            po.status AS status,
            '' AS sku,
            'Merchandise' AS category,
            0 AS prev_qty,
            po.unit_price AS new_qty,
            po.remarks AS remarks,
            po.batch_id AS extra_1,
            po.total_amount AS extra_2,
            po.created_by AS performed_by_id,
            COALESCE(po.admin_id, po.approved_by, 0) AS approved_by_id
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN users u_adm ON po.admin_id = u_adm.id
        WHERE po.station_id = ?

        UNION ALL

        SELECT
            fpo.created_at AS date_time,
            fpo.po_number AS reference_no,
            'Purchase Orders' AS module,
            'Fuel PO' AS transaction_type,
            ft.name AS product_fuel,
            fpo.volume AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u_mgr.first_name, ' ', u_mgr.last_name), ' '), u_mgr.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_adm.first_name, ' ', u_adm.last_name), ' '), u_adm.username, '—') AS approved_by,
            fpo.status AS status,
            '' AS sku,
            'Fuel' AS category,
            0 AS prev_qty,
            fpo.unit_price AS new_qty,
            fpo.notes AS remarks,
            fpo.batch_id AS extra_1,
            fpo.total_amount AS extra_2,
            fpo.created_by AS performed_by_id,
            COALESCE(fpo.approved_by, 0) AS approved_by_id
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u_mgr ON fpo.created_by = u_mgr.id
        LEFT JOIN users u_adm ON fpo.approved_by = u_adm.id
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        WHERE fpo.station_id = ?
    ) AS orders_ledger
    ";
    $params = [$station_id, $station_id];
} elseif ($active_tab === 'deliveries') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            do.delivery_date AS date_time,
            do.delivery_ref AS reference_no,
            'Deliveries' AS module,
            CASE WHEN do.delivery_type = 'fuel' THEN 'Fuel Delivery' ELSE 'Merchandise Delivery' END AS transaction_type,
            do.product AS product_fuel,
            do.actual_quantity AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_adm.first_name, ' ', u_adm.last_name), ' '), u_adm.username, '—') AS approved_by,
            do.status AS status,
            do.unit AS sku,
            do.category AS category,
            do.expected_quantity AS prev_qty,
            do.damaged_quantity AS new_qty,
            do.remarks AS remarks,
            do.supplier AS extra_1,
            do.dr_number AS extra_2,
            do.encoded_by AS performed_by_id,
            COALESCE(do.admin_id, do.finalized_by, do.resolved_by, 0) AS approved_by_id
        FROM deliveries_oversight do
        LEFT JOIN users u ON do.encoded_by = u.id
        LEFT JOIN users u_adm ON do.admin_id = u_adm.id
        WHERE do.station_id = ?
    ) AS deliveries_ledger
    ";
    $params = [$station_id];
} elseif ($active_tab === 'stock_in') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            msi.encoded_at AS date_time,
            msi.po_number AS reference_no,
            'Stock-In' AS module,
            'Merchandise Stock-In' AS transaction_type,
            msi.product_name AS product_fuel,
            msi.qty_received AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            '—' AS approved_by,
            'Completed' AS status,
            msi.sku AS sku,
            msi.category AS category,
            msi.stock_before AS prev_qty,
            msi.stock_after AS new_qty,
            msi.remarks AS remarks,
            msi.batch_ref AS extra_1,
            msi.unit_cost AS extra_2,
            msi.encoded_by AS performed_by_id,
            0 AS approved_by_id
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        WHERE msi.station_id = ?

        UNION ALL

        SELECT
            fd.delivery_date AS date_time,
            fd.invoice_no AS reference_no,
            'Stock-In' AS module,
            'Fuel Stock-In' AS transaction_type,
            fd.fuel_type AS product_fuel,
            fd.delivery_liters AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_val.first_name, ' ', u_val.last_name), ' '), u_val.username, '—') AS approved_by,
            'Completed' AS status,
            '' AS sku,
            'Fuel' AS category,
            0 AS prev_qty,
            0 AS new_qty,
            fd.notes AS remarks,
            fd.tank_assigned AS extra_1,
            fd.supplier AS extra_2,
            fd.received_by AS performed_by_id,
            COALESCE(fd.verified_by, 0) AS approved_by_id
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users u_val ON fd.verified_by = u_val.id
        WHERE fd.station_id = ? AND fd.status IN ('Verified','Approved')
    ) AS stock_in_ledger
    ";
    $params = [$station_id, $station_id];
} elseif ($active_tab === 'adjustments') {
    $union_sql = "
    SELECT * FROM (
        SELECT
            il.created_at AS date_time,
            '—' AS reference_no,
            'Inventory Adjustments' AS module,
            CASE 
                WHEN il.notes LIKE '%damaged%' THEN 'Damaged Item'
                WHEN il.notes LIKE '%variance%' OR il.notes LIKE '%correction%' OR il.notes LIKE '%count%' THEN 'Stock Correction'
                ELSE 'Adjustment'
            END AS transaction_type,
            ip.product_name AS product_fuel,
            il.quantity_change AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            '—' AS approved_by,
            'Approved' AS status,
            ip.sku AS sku,
            ip.category AS category,
            il.quantity_before AS prev_qty,
            il.quantity_after AS new_qty,
            il.notes AS remarks,
            il.reference_type AS extra_1,
            il.reference_id AS extra_2,
            il.user_id AS performed_by_id,
            0 AS approved_by_id
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.station_id = ? AND il.action = 'adjustment'

        UNION ALL

        SELECT
            fa.created_at AS date_time,
            '—' AS reference_no,
            'Inventory Adjustments' AS module,
            CASE 
                WHEN fa.adjustment_type = 'variance' OR fa.reason LIKE '%variance%' OR fa.notes LIKE '%variance%' THEN 'Fuel Variance'
                WHEN fa.adjustment_type = 'correction' OR fa.reason LIKE '%correction%' OR fa.notes LIKE '%correction%' OR fa.notes LIKE '%count%' THEN 'Stock Correction'
                ELSE 'Adjustment'
            END AS transaction_type,
            fa.fuel_type AS product_fuel,
            fa.liters AS quantity_liters,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS performed_by,
            COALESCE(NULLIF(CONCAT(u_app.first_name, ' ', u_app.last_name), ' '), u_app.username, '—') AS approved_by,
            fa.status AS status,
            '' AS sku,
            'Fuel' AS category,
            fa.previous_value AS prev_qty,
            fa.new_value AS new_qty,
            fa.reason AS remarks,
            fa.notes AS extra_1,
            '' AS extra_2,
            fa.user_id AS performed_by_id,
            COALESCE(fa.approved_by, 0) AS approved_by_id
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        LEFT JOIN users u_app ON fa.approved_by = u_app.id
        WHERE fa.station_id = ? AND fa.status = 'Approved'
    ) AS adjustments_ledger
    ";
    $params = [$station_id, $station_id];
}

// ── OUTER WHERE BUILDER ───────────────────────────────────────
$outer_where = " WHERE 1=1 ";
$outer_params = [];

if ($search !== '') {
    $outer_where .= " AND (product_fuel LIKE ? OR remarks LIKE ?)";
    $s_term = "%$search%";
    $outer_params[] = $s_term;
    $outer_params[] = $s_term;
}
if ($ref_no !== '') {
    $outer_where .= " AND (reference_no LIKE ? OR extra_1 LIKE ? OR extra_2 LIKE ?)";
    $r_term = "%$ref_no%";
    $outer_params[] = $r_term;
    $outer_params[] = $r_term;
    $outer_params[] = $r_term;
}
if ($start_date !== '' && $end_date !== '') {
    $outer_where .= " AND DATE(date_time) BETWEEN ? AND ?";
    $outer_params[] = $start_date;
    $outer_params[] = $end_date;
}
if ($category !== '') {
    $outer_where .= " AND category = ?";
    $outer_params[] = $category;
}
if ($move_type !== '') {
    $outer_where .= " AND transaction_type = ?";
    $outer_params[] = $move_type;
}
if ($perf_by !== '') {
    $outer_where .= " AND performed_by_id = ?";
    $outer_params[] = (int)$perf_by;
}
if ($status !== '') {
    $outer_where .= " AND status = ?";
    $outer_params[] = $status;
}

$full_sql = "SELECT * FROM (" . $union_sql . ") AS ledger " . $outer_where . " ORDER BY date_time DESC";
$all_params = array_merge($params, $outer_params);

// ── Execute query and fetch records ───────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare($full_sql);
    $stmt->execute($all_params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Database Query Error: " . $e->getMessage() . " <br>SQL: " . $full_sql);
}

// ── PRINT & EXPORT LOGIC ─────────────────────────────────────
if (isset($_GET['print'])) {
    // Fetch station info
    $station_name = 'Petron Carmen';
    $station_address = 'Vamenta Blvd., Carmen, Cagayan de Oro';
    try {
        $st_stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
        $st_stmt->execute([$station_id]);
        $station = $st_stmt->fetch(PDO::FETCH_ASSOC);
        if ($station) {
            if (!empty($station['name'])) $station_name = $station['name'];
            if (!empty($station['address'])) $station_address = $station['address'];
        }
    } catch (Exception $e) {}
    
    $tab_labels = [
        'merch' => 'Merchandise Movements',
        'fuel' => 'Fuel Movements',
        'requests' => 'Purchase Requests',
        'orders' => 'Purchase Orders',
        'deliveries' => 'Deliveries Ledger',
        'stock_in' => 'Stock-In Records',
        'adjustments' => 'Inventory Adjustments'
    ];
    $title = $tab_labels[$active_tab] ?? 'Inventory History';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title><?= htmlspecialchars($title) ?> Report - <?= htmlspecialchars($station_name) ?></title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; font-size: 11px; color: #333; }
            .header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #002F70; padding-bottom: 10px; }
            .header h1 { margin: 0; color: #002F70; font-size: 18px; text-transform: uppercase; }
            .header p { margin: 3px 0; color: #555; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #cbd5e1; padding: 7px 9px; text-align: left; }
            th { background-color: #002F70; color: white; font-weight: bold; text-transform: uppercase; font-size: 9px; }
            tr:nth-child(even) { background-color: #f8fafc; }
            .right { text-align: right; }
            .badge { display: inline-block; padding: 2px 5px; border-radius: 3px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
            .badge-delivery { background: #dcfce7; color: #166534; }
            .badge-sale { background: #fee2e2; color: #991b1b; }
            .badge-adjustment { background: #fef9c3; color: #854d0e; }
        </style>
    </head>
    <body onload="window.print()">
        <div class="header">
            <h1><?= htmlspecialchars($station_name) ?></h1>
            <p><?= htmlspecialchars($station_address) ?></p>
            <h3><?= htmlspecialchars($title) ?></h3>
            <p>Period: <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference No.</th>
                    <th>Module</th>
                    <th>Transaction</th>
                    <th>Product/Fuel</th>
                    <th class="right">Quantity/Liters</th>
                    <th>Performed By</th>
                    <th>Approved By</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" style="text-align:center;">No history records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                            <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                            <td><?= htmlspecialchars($r['module']) ?></td>
                            <td><?= htmlspecialchars($r['transaction_type']) ?></td>
                            <td><strong><?= htmlspecialchars($r['product_fuel']) ?></strong></td>
                            <td class="right">
                                <?php 
                                $v = (float)$r['quantity_liters'];
                                if ($v == 0) echo '—';
                                else echo ($v > 0 ? '+' : '') . number_format($v, 2);
                                ?>
                            </td>
                            <td><?= htmlspecialchars($r['performed_by']) ?></td>
                            <td><?= htmlspecialchars($r['approved_by']) ?></td>
                            <td><?= htmlspecialchars($r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['export'])) {
    $etype = $_GET['export'];
    $filename = $active_tab . "_history_" . date('Ymd');
    
    if ($etype === 'csv' || $etype === 'excel') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        
        fputcsv($out, ['Date', 'Reference No.', 'Module', 'Transaction', 'Product/Fuel', 'Quantity/Liters', 'Performed By', 'Approved By', 'Status', 'Remarks']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['date_time'],
                $r['reference_no'],
                $r['module'],
                $r['transaction_type'],
                $r['product_fuel'],
                $r['quantity_liters'],
                $r['performed_by'],
                $r['approved_by'],
                $r['status'],
                $r['remarks']
            ]);
        }
        fclose($out);
        exit;
    }
}

// ── Calculate Dynamic KPI Cards ────────────────────────────────
$kpis = ['total' => count($rows), 'k1' => 0, 'k2' => 0, 'k3' => 0];

if ($active_tab === 'merch') {
    $kpi_labels = ['Total Movements', 'Total Deliveries', 'Total Sales', 'Total Adjustments'];
    foreach ($rows as $r) {
        $tt = strtolower($r['transaction_type']);
        if ($tt === 'delivery') {
            $kpis['k1'] += (float)$r['quantity_liters'];
        } elseif ($tt === 'sale') {
            $kpis['k2'] += abs((float)$r['quantity_liters']);
        } elseif (strpos($tt, 'adj') !== false || strpos($tt, 'correct') !== false || strpos($tt, 'damage') !== false) {
            $kpis['k3']++;
        }
    }
} elseif ($active_tab === 'fuel') {
    $kpi_labels = ['Total Movements', 'Fuel Deliveries', 'Fuel Dispensed', 'Fuel Adjustments'];
    foreach ($rows as $r) {
        $tt = strtolower($r['transaction_type']);
        if ($tt === 'delivery') {
            $kpis['k1'] += (float)$r['quantity_liters'];
        } elseif ($tt === 'dispensed') {
            $kpis['k2'] += abs((float)$r['quantity_liters']);
        } elseif (strpos($tt, 'adj') !== false || strpos($tt, 'variance') !== false || strpos($tt, 'correct') !== false) {
            $kpis['k3']++;
        }
    }
} elseif ($active_tab === 'requests') {
    $kpi_labels = ['Total Requests', 'Pending Requests', 'Approved Requests', 'Rejected / Validated'];
    foreach ($rows as $r) {
        $st = strtolower($r['status']);
        if ($st === 'pending') {
            $kpis['k1']++;
        } elseif ($st === 'approved') {
            $kpis['k2']++;
        } elseif ($st === 'rejected' || $st === 'validated') {
            $kpis['k3']++;
        }
    }
} elseif ($active_tab === 'orders') {
    $kpi_labels = ['Total Orders', 'Pending Admin Validation', 'Finalized / Approved', 'Rejected / Cancelled'];
    foreach ($rows as $r) {
        $st = strtolower($r['status']);
        if (strpos($st, 'validation') !== false || $st === 'pending approval' || $st === 'pending') {
            $kpis['k1']++;
        } elseif (strpos($st, 'finalized') !== false || $st === 'approved' || $st === 'approved po' || $st === 'official') {
            $kpis['k2']++;
        } elseif (strpos($st, 'reject') !== false || $st === 'cancelled') {
            $kpis['k3']++;
        }
    }
} elseif ($active_tab === 'deliveries') {
    $kpi_labels = ['Total Deliveries', 'Expected Deliveries', 'Completed / Delivered', 'Returned / Flagged'];
    foreach ($rows as $r) {
        $st = strtolower($r['status']);
        if (strpos($st, 'expected') !== false || $st === 'pending') {
            $kpis['k1']++;
        } elseif ($st === 'completed' || $st === 'delivered' || $st === 'finalized') {
            $kpis['k2']++;
        } elseif ($st === 'returned' || in_array($st, ['short', 'damaged', 'excess', 'mixed'])) {
            $kpis['k3']++;
        }
    }
} elseif ($active_tab === 'stock_in') {
    $kpi_labels = ['Total Stock-Ins', 'Merchandise Stock-In', 'Fuel Stock-In', 'Total Qty Received'];
    foreach ($rows as $r) {
        $tt = strtolower($r['transaction_type']);
        if (strpos($tt, 'merchandise') !== false) {
            $kpis['k1']++;
        } elseif (strpos($tt, 'fuel') !== false) {
            $kpis['k2']++;
        }
        $kpis['k3'] += (float)$r['quantity_liters'];
    }
} elseif ($active_tab === 'adjustments') {
    $kpi_labels = ['Total Adjustments', 'Stock Corrections', 'Damaged / Variance Logs', 'Other Adjustments'];
    foreach ($rows as $r) {
        $tt = strtolower($r['transaction_type']);
        if (strpos($tt, 'correction') !== false) {
            $kpis['k1']++;
        } elseif (strpos($tt, 'damaged') !== false || strpos($tt, 'variance') !== false) {
            $kpis['k2']++;
        } else {
            $kpis['k3']++;
        }
    }
}

// Fetch filter lists
$categories_list = [];
try {
    $categories_stmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories_list = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$users_list = [];
try {
    $users_stmt = $pdo->query("SELECT id, username, CONCAT(first_name, ' ', last_name) AS fullname FROM users ORDER BY username");
    $users_list = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* Header standardization */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:#00264D !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#64748b; margin-top:4px; }

/* Custom Outlined Buttons for Petron-clean Look */
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
.flt-btn-search { color:#002F70 !important; border-color:#002F70 !important; }
.flt-btn-search:hover { background:#002F70 !important; color:#fff !important; }
.flt-btn-reset { color:#6b7280 !important; border-color:#6b7280 !important; }
.flt-btn-reset:hover { background:#6b7280 !important; color:#fff !important; }
.flt-btn-excel { color:#1d6f42 !important; border-color:#1d6f42 !important; }
.flt-btn-excel:hover { background:#1d6f42 !important; color:#fff !important; }
.flt-btn-csv { color:#002F70 !important; border-color:#002F70 !important; }
.flt-btn-csv:hover { background:#002F70 !important; color:#fff !important; }
.flt-btn-pdf { color:#dc2626 !important; border-color:#dc2626 !important; }
.flt-btn-pdf:hover { background:#dc2626 !important; color:#fff !important; }

/* Tabs Layout */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; flex-wrap:wrap; }
.tab-btn { padding:10px 20px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }

/* Summary Cards */
.sm-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px; }
.sm-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
.sm-card .det { display:flex; flex-direction:column; }
.sm-card .det span:first-child { font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.5px; }
.sm-card .det span:last-child { font-size:20px; font-weight:800; color:#1e293b; margin-top:4px; line-height:1; }
.sm-card i { font-size:24px; color:#94a3b8; opacity:0.6; }
.sm-card.blue i { color:#002F70; }
.sm-card.green i { color:#16a34a; }
.sm-card.red i { color:#dc2626; }
.sm-card.yellow i { color:#ea580c; }

/* Filter Bar */
.filter-bar { background:#fff; border-radius:8px; padding:14px 16px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; }
.filter-grid { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.filter-grid .fg { display:flex; flex-direction:column; gap:4px; flex:1; min-width:140px; }
.filter-grid label { font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.4px; }
.filter-grid input, .filter-grid select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12.5px; outline:none; color:#334155; height:36px; box-sizing:border-box; }
.filter-grid input:focus, .filter-grid select:focus { border-color:#002F70; }

/* Table design */
.po-table-wrap { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden; border:1px solid #e2e8f0; }
.po-table { width:100%; border-collapse:collapse; font-size:11px; }
.po-table thead tr { background:#002F70; }
.po-table thead th { padding:10px 12px; text-align:left; font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; border-bottom:2px solid #001a3d; vertical-align:middle; }
.po-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.po-table tbody tr:hover td { background:#eff6ff; }
.po-table tbody td { padding:10px 12px; color:#334155; vertical-align:middle; font-size:11px; line-height:1.3; }

.nowrap { white-space:nowrap; }
.right { text-align:right; }
.badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; }
.badge-delivery { background:#dcfce7; color:#166534; }
.badge-sale { background:#fee2e2; color:#991b1b; }
.badge-adjustment { background:#fef9c3; color:#854d0e; }
.badge-other { background:#f1f5f9; color:#475569; }

.txn-btn { display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:4px 8px; border-radius:4px; font-size:10.5px; font-weight:600; cursor:pointer; text-decoration:none; transition:all 0.15s; border:1px solid transparent; background:none; }
.txn-btn-view { color:#002F70; border-color:#002F70; }
.txn-btn-view:hover { background:#002F70; color:#fff; }
.txn-btn-print { color:#475569; border-color:#cbd5e1; }
.txn-btn-print:hover { background:#cbd5e1; color:#1e293b; }

/* Centered Modals design */
.modal-ov { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px; }
.modal-ov.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:100%; max-width:550px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); padding:24px; position:relative; }
.modal-box h3 {
    margin: -24px -24px 16px -24px;
    font-size: 16px;
    color: #fff !important;
    font-weight: 700;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 8px;
    background: #00264D !important;
    padding: 16px 20px;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}
.info-sec { background:#f8fafc; padding:6px 12px; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; border-left:3px solid #002F70; margin:16px 0 8px; }
.info-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #f1f5f9; font-size:12.5px; }
.info-row strong { color:#64748b; font-weight:600; }
.info-row span { color:#1e293b; font-weight:500; }
.modal-foot { display:flex; justify-content:flex-end; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:12px; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-history"></i> Inventory Management</h1>
        <div class="sub">Monitor inventory across merchandise, fuel, and movement history &middot; Today: <?= date('F d, Y') ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="flt-btn flt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="flt-btn flt-btn-csv" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <button class="flt-btn flt-btn-pdf" onclick="printInventoryHistory()" title="Export to PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
    </div>
</div>

<!-- Inventory Navigation Tabs -->
<div style="display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; flex-wrap:wrap;">
    <a href="admin_inventory_merchandise.php" class="tab-btn" style="padding:10px 20px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
        <i class="fas fa-box"></i> Merchandise Inventory
    </a>
    <a href="admin_inventory_fuel.php" class="tab-btn" style="padding:10px 20px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
        <i class="fas fa-gas-pump"></i> Fuel Inventory
    </a>
    <a href="admin_inventory_history.php" class="tab-btn active" style="padding:10px 20px; background:none; border:none; border-bottom:3px solid #002F70; font-size:13px; font-weight:600; color:#002F70; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
        <i class="fas fa-history"></i> Movement History
    </a>
</div>

<!-- History Sub-Tabs -->
<div class="tab-nav">
    <a href="?tab=merch" class="tab-btn <?= $active_tab === 'merch' ? 'active' : '' ?>">
        <i class="fas fa-box"></i> Merchandise
    </a>
    <a href="?tab=fuel" class="tab-btn <?= $active_tab === 'fuel' ? 'active' : '' ?>">
        <i class="fas fa-gas-pump"></i> Fuel
    </a>
    <a href="?tab=requests" class="tab-btn <?= $active_tab === 'requests' ? 'active' : '' ?>">
        <i class="fas fa-clipboard-list"></i> Purchase Requests
    </a>
    <a href="?tab=orders" class="tab-btn <?= $active_tab === 'orders' ? 'active' : '' ?>">
        <i class="fas fa-file-invoice-dollar"></i> Purchase Orders
    </a>
    <a href="?tab=deliveries" class="tab-btn <?= $active_tab === 'deliveries' ? 'active' : '' ?>">
        <i class="fas fa-shipping-fast"></i> Deliveries
    </a>
    <a href="?tab=stock_in" class="tab-btn <?= $active_tab === 'stock_in' ? 'active' : '' ?>">
        <i class="fas fa-boxes"></i> Stock-In
    </a>
    <a href="?tab=adjustments" class="tab-btn <?= $active_tab === 'adjustments' ? 'active' : '' ?>">
        <i class="fas fa-balance-scale"></i> Inventory Adjustments
    </a>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" action="" id="filterForm">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
        <div class="filter-grid">
            <div class="fg">
                <label>Search Product / Fuel</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search keyword...">
            </div>
            <div class="fg">
                <label>Reference No.</label>
                <input type="text" name="ref_no" value="<?= htmlspecialchars($ref_no) ?>" placeholder="Search Ref...">
            </div>
            <div class="fg">
                <label>Start Date</label>
                <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="fg">
                <label>End Date</label>
                <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            
            <?php if (in_array($active_tab, ['merch', 'requests', 'orders', 'deliveries', 'stock_in', 'adjustments'])): ?>
                <div class="fg">
                    <label>Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories_list as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="fg">
                <label>Transaction Type</label>
                <select name="move_type">
                    <option value="">All Types</option>
                    <?php if ($active_tab === 'merch'): ?>
                        <option value="Delivery" <?= $move_type === 'Delivery' ? 'selected' : '' ?>>Delivery</option>
                        <option value="Sale" <?= $move_type === 'Sale' ? 'selected' : '' ?>>Sale</option>
                        <option value="Adjustment" <?= $move_type === 'Adjustment' ? 'selected' : '' ?>>Adjustment</option>
                        <option value="Stock Correction" <?= $move_type === 'Stock Correction' ? 'selected' : '' ?>>Stock Correction</option>
                        <option value="Damaged Item" <?= $move_type === 'Damaged Item' ? 'selected' : '' ?>>Damaged Item</option>
                    <?php elseif ($active_tab === 'fuel'): ?>
                        <option value="Delivery" <?= $move_type === 'Delivery' ? 'selected' : '' ?>>Delivery</option>
                        <option value="Dispensed" <?= $move_type === 'Dispensed' ? 'selected' : '' ?>>Dispensed</option>
                        <option value="Adjustment" <?= $move_type === 'Adjustment' ? 'selected' : '' ?>>Adjustment</option>
                        <option value="Fuel Variance" <?= $move_type === 'Fuel Variance' ? 'selected' : '' ?>>Fuel Variance</option>
                        <option value="Stock Correction" <?= $move_type === 'Stock Correction' ? 'selected' : '' ?>>Stock Correction</option>
                    <?php elseif ($active_tab === 'requests'): ?>
                        <option value="Merchandise Request" <?= $move_type === 'Merchandise Request' ? 'selected' : '' ?>>Merchandise Request</option>
                        <option value="Fuel Request" <?= $move_type === 'Fuel Request' ? 'selected' : '' ?>>Fuel Request</option>
                    <?php elseif ($active_tab === 'orders'): ?>
                        <option value="Merchandise PO" <?= $move_type === 'Merchandise PO' ? 'selected' : '' ?>>Merchandise PO</option>
                        <option value="Fuel PO" <?= $move_type === 'Fuel PO' ? 'selected' : '' ?>>Fuel PO</option>
                    <?php elseif ($active_tab === 'deliveries'): ?>
                        <option value="Merchandise Delivery" <?= $move_type === 'Merchandise Delivery' ? 'selected' : '' ?>>Merchandise Delivery</option>
                        <option value="Fuel Delivery" <?= $move_type === 'Fuel Delivery' ? 'selected' : '' ?>>Fuel Delivery</option>
                    <?php elseif ($active_tab === 'stock_in'): ?>
                        <option value="Merchandise Stock-In" <?= $move_type === 'Merchandise Stock-In' ? 'selected' : '' ?>>Merchandise Stock-In</option>
                        <option value="Fuel Stock-In" <?= $move_type === 'Fuel Stock-In' ? 'selected' : '' ?>>Fuel Stock-In</option>
                    <?php elseif ($active_tab === 'adjustments'): ?>
                        <option value="Adjustment" <?= $move_type === 'Adjustment' ? 'selected' : '' ?>>Adjustment</option>
                        <option value="Stock Correction" <?= $move_type === 'Stock Correction' ? 'selected' : '' ?>>Stock Correction</option>
                        <option value="Damaged Item" <?= $move_type === 'Damaged Item' ? 'selected' : '' ?>>Damaged Item</option>
                        <option value="Fuel Variance" <?= $move_type === 'Fuel Variance' ? 'selected' : '' ?>>Fuel Variance</option>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="fg">
                <label>Performed By</label>
                <select name="perf_by">
                    <option value="">All Users</option>
                    <?php foreach ($users_list as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $perf_by == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['fullname'] ?: $user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fg">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="Completed" <?= $status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Rejected" <?= $status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="Official" <?= $status === 'Official' ? 'selected' : '' ?>>Official</option>
                    <option value="Verified" <?= $status === 'Verified' ? 'selected' : '' ?>>Verified</option>
                    <option value="Pending Admin Validation" <?= $status === 'Pending Admin Validation' ? 'selected' : '' ?>>Pending Admin Validation</option>
                    <option value="Expected Delivery" <?= $status === 'Expected Delivery' ? 'selected' : '' ?>>Expected Delivery</option>
                </select>
            </div>
            
            <div style="display:flex;gap:8px;">
                <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-filter"></i> Filter</button>
                <a href="?tab=<?= htmlspecialchars($active_tab) ?>" class="flt-btn flt-btn-reset"><i class="fas fa-times"></i> Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- Dynamic Summary Cards -->
<div class="sm-cards">
    <div class="sm-card blue">
        <div class="det">
            <span><?= htmlspecialchars($kpi_labels[0]) ?></span>
            <span><?= number_format($kpis['total']) ?></span>
        </div>
        <i class="fas fa-folder-open"></i>
    </div>
    <div class="sm-card green">
        <div class="det">
            <span><?= htmlspecialchars($kpi_labels[1]) ?></span>
            <span>
                <?php 
                if ($active_tab === 'merch' || $active_tab === 'fuel') echo number_format($kpis['k1'], 2) . ($active_tab === 'fuel' ? ' L' : '');
                else echo number_format($kpis['k1']);
                ?>
            </span>
        </div>
        <i class="fas fa-check-circle"></i>
    </div>
    <div class="sm-card red">
        <div class="det">
            <span><?= htmlspecialchars($kpi_labels[2]) ?></span>
            <span>
                <?php 
                if ($active_tab === 'merch' || $active_tab === 'fuel') echo number_format($kpis['k2'], 2) . ($active_tab === 'fuel' ? ' L' : '');
                elseif ($active_tab === 'stock_in') echo number_format($kpis['k2']);
                else echo number_format($kpis['k2']);
                ?>
            </span>
        </div>
        <i class="fas fa-exchange-alt"></i>
    </div>
    <div class="sm-card yellow">
        <div class="det">
            <span><?= htmlspecialchars($kpi_labels[3]) ?></span>
            <span>
                <?php 
                if ($active_tab === 'stock_in') echo number_format($kpis['k3'], 2) . ' L/pcs';
                else echo number_format($kpis['k3']);
                ?>
            </span>
        </div>
        <i class="fas fa-exclamation-triangle"></i>
    </div>
</div>

<!-- Unified Ledger Table -->
<div class="po-table-wrap">
    <table class="po-table">
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Reference No.</th>
                <th>Module</th>
                <th>Transaction</th>
                <th>Product/Fuel</th>
                <th class="right">Quantity/Liters</th>
                <th>Performed By</th>
                <th>Approved By</th>
                <th>Status</th>
                <th style="text-align: center; width: 150px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 24px; color: #64748b;">
                        <i class="fas fa-history" style="font-size: 28px; margin-bottom: 8px; display: block; opacity: 0.3;"></i>
                        No history records found matching current criteria.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap"><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                        <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                        <td><span style="font-weight: 600; color: #475569;"><?= htmlspecialchars($r['module']) ?></span></td>
                        <td><span class="badge badge-other"><?= htmlspecialchars($r['transaction_type']) ?></span></td>
                        <td><strong><?= htmlspecialchars($r['product_fuel']) ?></strong></td>
                        <td class="right nowrap" style="font-weight:700; color: <?= (float)$r['quantity_liters'] > 0 ? '#16a34a' : ((float)$r['quantity_liters'] < 0 ? '#dc2626' : '#475569') ?>;">
                            <?php 
                            $val = (float)$r['quantity_liters'];
                            $unit = (strtolower($r['category']) === 'fuel' || strpos(strtolower($r['transaction_type']), 'fuel') !== false) ? ' L' : '';
                            if ($val == 0) echo '—';
                            else echo ($val > 0 ? '+' : '') . number_format($val, 2) . $unit;
                            ?>
                        </td>
                        <td><?= htmlspecialchars($r['performed_by']) ?></td>
                        <td><?= htmlspecialchars($r['approved_by']) ?></td>
                        <td>
                            <?php 
                            $status_class = 'badge-other';
                            $st = strtolower($r['status']);
                            if ($st === 'completed' || $st === 'approved' || $st === 'official' || $st === 'verified') {
                                $status_class = 'badge-delivery';
                            } elseif ($st === 'pending' || strpos($st, 'validation') !== false || $st === 'draft') {
                                $status_class = 'badge-adjustment';
                            } elseif (strpos($st, 'reject') !== false || strpos($st, 'cancel') !== false) {
                                $status_class = 'badge-sale';
                            }
                            ?>
                            <span class="badge <?= $status_class ?>"><?= htmlspecialchars($r['status']) ?></span>
                        </td>
                        <td class="nowrap" style="text-align: center;">
                            <button class="txn-btn txn-btn-view" onclick="showDetails(<?= htmlspecialchars(json_encode($r)) ?>)">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            <button class="txn-btn txn-btn-print" onclick="printSingleRecord(<?= htmlspecialchars(json_encode($r)) ?>)">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Unified View Details Centered Modal -->
<div id="detailsModal" class="modal-ov">
    <div class="modal-box" style="max-width: 550px;">
        <h3><i class="fas fa-info-circle"></i> Transaction Details</h3>
        
        <div class="info-sec">Reference Details</div>
        <div class="info-row"><strong>Date & Time</strong><span id="detDate">—</span></div>
        <div class="info-row"><strong>Reference No.</strong><span id="detRefNo">—</span></div>
        <div class="info-row"><strong>Module</strong><span id="detModule">—</span></div>
        <div class="info-row"><strong>Transaction Type</strong><span id="detTxnType">—</span></div>
        <div class="info-row"><strong>Status</strong><span id="detStatus">—</span></div>

        <div class="info-sec">Product & Quantity Details</div>
        <div class="info-row"><strong>Product / Fuel</strong><span id="detProduct">—</span></div>
        <div id="rowSku" class="info-row"><strong>SKU / Unit</strong><span id="detSku">—</span></div>
        <div id="rowCategory" class="info-row"><strong>Category</strong><span id="detCategory">—</span></div>
        <div class="info-row"><strong>Quantity / Liters</strong><span id="detQty">—</span></div>
        <div id="rowPrevQty" class="info-row"><strong>Previous Stock/Vol</strong><span id="detPrevQty">—</span></div>
        <div id="rowNewQty" class="info-row"><strong>New Stock/Vol</strong><span id="detNewQty">—</span></div>

        <div class="info-sec">User Responsibility</div>
        <div class="info-row"><strong>Performed By</strong><span id="detPerformedBy">—</span></div>
        <div class="info-row"><strong>Approved By</strong><span id="detApprovedBy">—</span></div>

        <div class="info-sec">Remarks & Notes</div>
        <div style="padding:10px; background:#f8fafc; border-radius:6px; font-size:12.5px; line-height:1.5; color:#334155; margin-top:8px; border:1px solid #e2e8f0;">
            <span id="detRemarks">—</span>
        </div>

        <div class="modal-foot">
            <button onclick="closeModal('detailsModal')" class="flt-btn flt-btn-reset">Close</button>
        </div>
    </div>
</div>

<script>
function showDetails(data) {
    document.getElementById('detDate').textContent = data.date_time;
    document.getElementById('detRefNo').textContent = data.reference_no || '—';
    document.getElementById('detModule').textContent = data.module;
    document.getElementById('detTxnType').textContent = data.transaction_type;
    
    // Status Badge inside modal
    let statusClass = 'badge-other';
    let st = data.status.toLowerCase();
    if (st.includes('completed') || st.includes('official') || st.includes('verified') || st.includes('approved')) {
        statusClass = 'badge-delivery';
    } else if (st.includes('pending') || st.includes('draft') || st.includes('validation')) {
        statusClass = 'badge-adjustment';
    } else if (st.includes('reject') || st.includes('cancel')) {
        statusClass = 'badge-sale';
    }
    document.getElementById('detStatus').innerHTML = `<span class="badge ${statusClass}">${data.status}</span>`;

    document.getElementById('detProduct').textContent = data.product_fuel;
    
    if (data.sku) {
        document.getElementById('rowSku').style.display = 'flex';
        document.getElementById('detSku').textContent = data.sku;
    } else {
        document.getElementById('rowSku').style.display = 'none';
    }

    if (data.category) {
        document.getElementById('rowCategory').style.display = 'flex';
        document.getElementById('detCategory').textContent = data.category;
    } else {
        document.getElementById('rowCategory').style.display = 'none';
    }

    // Format quantity
    let qtyVal = parseFloat(data.quantity_liters);
    let qtyStr = isNaN(qtyVal) ? '—' : (qtyVal > 0 ? '+' : '') + qtyVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (data.module === 'Fuel' || data.transaction_type.toLowerCase().includes('fuel') || data.category === 'Fuel') {
        qtyStr += ' L';
    }
    document.getElementById('detQty').textContent = qtyStr;

    // Previous and New Stock/Vol
    let prevVal = parseFloat(data.prev_qty);
    let newVal = parseFloat(data.new_qty);
    if (!isNaN(prevVal) && prevVal !== 0) {
        document.getElementById('rowPrevQty').style.display = 'flex';
        document.getElementById('detPrevQty').textContent = prevVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        document.getElementById('rowPrevQty').style.display = 'none';
    }

    if (!isNaN(newVal) && newVal !== 0) {
        document.getElementById('rowNewQty').style.display = 'flex';
        document.getElementById('detNewQty').textContent = newVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        document.getElementById('rowNewQty').style.display = 'none';
    }

    document.getElementById('detPerformedBy').textContent = data.performed_by;
    document.getElementById('detApprovedBy').textContent = data.approved_by || '—';
    document.getElementById('detRemarks').textContent = data.remarks || '—';

    document.getElementById('detailsModal').classList.add('show');
}

function printSingleRecord(data) {
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    
    let qtyVal = parseFloat(data.quantity_liters);
    let qtyStr = isNaN(qtyVal) ? '—' : (qtyVal > 0 ? '+' : '') + qtyVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (data.module === 'Fuel' || data.transaction_type.toLowerCase().includes('fuel') || data.category === 'Fuel') {
        qtyStr += ' L';
    }

    let extraRowsHtml = '';
    if (data.sku) extraRowsHtml += `<tr><th>SKU / Unit</th><td>${data.sku}</td></tr>`;
    if (data.category) extraRowsHtml += `<tr><th>Category</th><td>${data.category}</td></tr>`;
    
    let prevVal = parseFloat(data.prev_qty);
    let newVal = parseFloat(data.new_qty);
    if (!isNaN(prevVal) && prevVal !== 0) extraRowsHtml += `<tr><th>Previous Stock/Vol</th><td>${prevVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td></tr>`;
    if (!isNaN(newVal) && newVal !== 0) extraRowsHtml += `<tr><th>New Stock/Vol</th><td>${newVal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td></tr>`;

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Transaction Record - ${data.reference_no || 'No Ref'}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 40px; color: #333; }
                .logo-header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #002F70; padding-bottom: 15px; }
                .logo-header h1 { margin: 0; color: #002F70; font-size: 24px; text-transform: uppercase; }
                .logo-header p { margin: 5px 0; color: #666; font-size: 13px; }
                .title { font-size: 18px; font-weight: bold; margin-bottom: 20px; color: #00264D; text-transform: uppercase; text-align: center; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f8fafc; color: #475569; font-weight: bold; width: 30%; }
                td { color: #1e293b; }
                .remarks-box { margin-top: 25px; padding: 15px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; }
                .remarks-box h4 { margin: 0 0 8px 0; color: #002F70; text-transform: uppercase; font-size: 12px; }
                .footer { margin-top: 50px; text-align: center; font-size: 11px; color: #888; border-top: 1px solid #eee; padding-top: 15px; }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            <div class="logo-header">
                <h1>PETRON CORPORATION</h1>
                <p>Carmen Station, Vamenta Blvd., Cagayan de Oro City</p>
            </div>
            <div class="title">Inventory Transaction Record</div>
            <table>
                <tr><th>Date & Time</th><td>${data.date_time}</td></tr>
                <tr><th>Reference No.</th><td>${data.reference_no || '—'}</td></tr>
                <tr><th>Module</th><td>${data.module}</td></tr>
                <tr><th>Transaction Type</th><td>${data.transaction_type}</td></tr>
                <tr><th>Status</th><td>${data.status}</td></tr>
                <tr><th>Product / Fuel</th><td><strong>${data.product_fuel}</strong></td></tr>
                <tr><th>Quantity / Liters</th><td><strong>${qtyStr}</strong></td></tr>
                ${extraRowsHtml}
                <tr><th>Performed By</th><td>${data.performed_by}</td></tr>
                <tr><th>Approved By</th><td>${data.approved_by || '—'}</td></tr>
            </table>
            <div class="remarks-box">
                <h4>Remarks & Notes</h4>
                <div>${data.remarks || 'No remarks recorded.'}</div>
            </div>
            <div class="footer">
                Printed on ${new Date().toLocaleString()} | Petron Carmen Station Management System
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
}

function printInventoryHistory() {
    const activeTab = '<?= $active_tab ?>';
    const startDate = '<?= htmlspecialchars($start_date) ?>';
    const endDate = '<?= htmlspecialchars($end_date) ?>';
    const search = '<?= htmlspecialchars($search) ?>';
    const refNo = '<?= htmlspecialchars($ref_no) ?>';
    const category = '<?= htmlspecialchars($category) ?>';
    const moveType = '<?= htmlspecialchars($move_type) ?>';
    const perfBy = '<?= htmlspecialchars($perf_by) ?>';
    const status = '<?= htmlspecialchars($status) ?>';
    
    let url = 'admin_inventory_history.php?print=1&tab=' + activeTab;
    url += '&start=' + encodeURIComponent(startDate);
    url += '&end=' + encodeURIComponent(endDate);
    if (search) url += '&search=' + encodeURIComponent(search);
    if (refNo) url += '&ref_no=' + encodeURIComponent(refNo);
    if (category) url += '&category=' + encodeURIComponent(category);
    if (moveType) url += '&move_type=' + encodeURIComponent(moveType);
    if (perfBy) url += '&perf_by=' + encodeURIComponent(perfBy);
    if (status) url += '&status=' + encodeURIComponent(status);
    
    let iframe = document.getElementById('printFrame');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'printFrame';
        iframe.style.position = 'absolute';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = 'none';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);
    }
    
    iframe.onload = function() {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) {
            window.open(url, '_blank');
        }
    };
    iframe.src = url;
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal-ov').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
