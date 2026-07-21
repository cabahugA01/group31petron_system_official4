<?php
// ============================================================
// Admin Inventory History Oversight - admin_inventory_history.php
// Rebuilt to support 5 required tabs: Purchase Orders, Deliveries,
// Stock-In, Inventory Updates, and Inventory Adjustments.
// Supports unified table format, custom PDF/print output, and details modals.
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
$active_tab = $_GET['tab']   ?? 'orders';
$allowed_tabs = ['orders', 'deliveries', 'stock_in', 'updates', 'adjustments'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'orders';
}

$search      = trim($_GET['search'] ?? '');
$ref_no      = trim($_GET['ref_no'] ?? '');
$move_type   = trim($_GET['move_type'] ?? '');
$status      = trim($_GET['status'] ?? '');

// ── AJAX Handlers ──────────────────────────────────────────────
if (isset($_GET['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_GET['action'];
    try {
        if ($action === 'get_po_details') {
            $po_no = trim($_GET['po_no'] ?? '');
            $po_type = trim($_GET['type'] ?? 'merch');
            
            $items = [];
            if ($po_type === 'fuel') {
                $stmt = $pdo->prepare("
                    SELECT ft.name AS product_name, fpo.volume AS quantity, fpo.unit_price, fpo.total_amount, 'L' AS unit
                    FROM fuel_purchase_orders fpo
                    LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                    WHERE (fpo.batch_id = ? OR fpo.po_number = ?) AND fpo.station_id = ?
                    ORDER BY fpo.id ASC
                ");
                $stmt->execute([$po_no, $po_no, $station_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("
                    SELECT product_name, quantity, unit_price, total_amount, 'pcs' AS unit
                    FROM purchase_orders
                    WHERE (batch_id = ? OR po_number = ?) AND station_id = ?
                    ORDER BY id ASC
                ");
                $stmt->execute([$po_no, $po_no, $station_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'items' => $items]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── DYNAMIC SQL BUILDER ───────────────────────────────────────
$union_sql = "";
$params = [];

if ($active_tab === 'orders') {
    $union_sql = "
    SELECT 
        po_no AS reference_no,
        MIN(pr_no) AS extra_1,
        MIN(supplier) AS supplier,
        MIN(generated_by) AS performed_by,
        MAX(date_only) AS date_time,
        MAX(status) AS status,
        MIN(po_type) AS extra_2,
        station_id
    FROM (
        SELECT 
            po.batch_id AS po_no,
            COALESCE(CONCAT('PR-', po.request_id), '—') AS pr_no,
            'Petron Corporation' AS supplier,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS generated_by,
            po.created_at AS date_only,
            po.status AS status,
            'merch' AS po_type,
            po.station_id
        FROM purchase_orders po
        LEFT JOIN users u ON po.admin_id = u.id
        WHERE po.batch_id IS NOT NULL AND po.batch_id != ''

        UNION ALL

        SELECT 
            fpo.batch_id AS po_no,
            COALESCE(CONCAT('PR-FUEL-', DATE_FORMAT(fpo.created_at, '%Y%m%d')), '—') AS pr_no,
            'Petron Corporation' AS supplier,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS generated_by,
            fpo.created_at AS date_only,
            fpo.status AS status,
            'fuel' AS po_type,
            fpo.station_id
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.approved_by = u.id
        WHERE fpo.batch_id IS NOT NULL AND fpo.batch_id != ''
    ) AS raw_orders
    WHERE station_id = ?
    GROUP BY po_no
    ";
    $params = [$station_id];
} elseif ($active_tab === 'deliveries') {
    $union_sql = "
    SELECT 
        do.delivery_ref AS reference_no,
        MAX(do.batch_id) AS extra_1,
        MAX(do.supplier) AS supplier,
        MAX(COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System')) AS performed_by,
        MAX(do.delivery_date) AS date_time,
        MAX(do.status) AS status,
        '' AS extra_2,
        do.station_id
    FROM deliveries_oversight do
    LEFT JOIN users u ON do.encoded_by = u.id
    WHERE do.station_id = ?
    GROUP BY do.delivery_ref
    ";
    $params = [$station_id];
} elseif ($active_tab === 'stock_in') {
    $union_sql = "
    SELECT 
        stock_in_no AS reference_no,
        MAX(po_no) AS extra_1,
        MAX(approved_by) AS performed_by,
        MAX(date_only) AS date_time,
        MAX(status) AS status,
        '' AS supplier,
        '' AS extra_2,
        station_id
    FROM (
        SELECT 
            COALESCE(msi.batch_ref, CONCAT('SI-', msi.id)) AS stock_in_no,
            msi.po_number AS po_no,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS approved_by,
            msi.encoded_at AS date_only,
            'Completed' AS status,
            msi.station_id
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id

        UNION ALL

        SELECT 
            COALESCE((SELECT delivery_ref FROM fuel_stock_in WHERE batch_ref = fd.invoice_no LIMIT 1), CONCAT('SIF-', fd.id)) AS stock_in_no,
            fd.invoice_no AS po_no,
            COALESCE(NULLIF(CONCAT(u_val.first_name, ' ', u_val.last_name), ' '), u_val.username, '—') AS approved_by,
            fd.delivery_date AS date_only,
            'Completed' AS status,
            fd.station_id
        FROM fuel_deliveries fd
        LEFT JOIN users u_val ON fd.verified_by = u_val.id
        WHERE fd.status IN ('Verified','Approved')
    ) AS raw_si
    WHERE station_id = ?
    GROUP BY stock_in_no
    ";
    $params = [$station_id];
} elseif ($active_tab === 'updates') {
    $union_sql = "
    SELECT 
        date_only AS date_time,
        item AS reference_no,
        type AS extra_1,
        previous AS supplier,
        new_val AS performed_by,
        updated_by AS status,
        unit AS extra_2,
        station_id
    FROM (
        SELECT 
            msi.encoded_at AS date_only,
            msi.product_name AS item,
            'Stock-In' AS type,
            CAST(msi.stock_before AS CHAR) AS previous,
            CAST(msi.stock_after AS CHAR) AS new_val,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS updated_by,
            'pcs' AS unit,
            msi.station_id
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id

        UNION ALL

        SELECT 
            mt.transaction_date AS date_only,
            mti.product_name AS item,
            'Stock-Out' AS type,
            '—' AS previous,
            '—' AS new_val,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS updated_by,
            'pcs' AS unit,
            mt.station_id
        FROM merchandise_transaction_items mti
        JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.validation_status IN ('Official','Completed','Approved','Adjusted') AND mti.item_type = 'merchandise'

        UNION ALL

        SELECT 
            il.created_at AS date_only,
            ip.product_name AS item,
            CASE 
                WHEN il.notes LIKE '%correction%' OR il.notes LIKE '%variance%' OR il.notes LIKE '%count%' THEN 'Correction'
                ELSE 'Adjustment'
            END AS type,
            CAST(il.quantity_before AS CHAR) AS previous,
            CAST(il.quantity_after AS CHAR) AS new_val,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS updated_by,
            'pcs' AS unit,
            il.station_id
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.action = 'adjustment'

        UNION ALL

        SELECT 
            fd.created_at AS date_only,
            fd.fuel_type AS item,
            'Stock-In' AS type,
            CAST(COALESCE((SELECT level_before FROM fuel_stock_in WHERE delivery_ref = CONCAT('DEL-', fd.id) OR batch_ref = fd.invoice_no LIMIT 1), 0) AS CHAR) AS previous,
            CAST(COALESCE((SELECT level_after FROM fuel_stock_in WHERE delivery_ref = CONCAT('DEL-', fd.id) OR batch_ref = fd.invoice_no LIMIT 1), 0) AS CHAR) AS new_val,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS updated_by,
            'L' AS unit,
            fd.station_id
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.status IN ('Verified','Approved')

        UNION ALL

        SELECT 
            ft.transaction_date AS date_only,
            ft.fuel_type AS item,
            'Stock-Out' AS type,
            '—' AS previous,
            '—' AS new_val,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS updated_by,
            'L' AS unit,
            ft.station_id
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.status IN ('Approved','approved','Completed')

        UNION ALL

        SELECT 
            fa.created_at AS date_only,
            fa.fuel_type AS item,
            CASE 
                WHEN fa.adjustment_type = 'correction' OR fa.reason LIKE '%correction%' OR fa.notes LIKE '%correction%' OR fa.notes LIKE '%count%' THEN 'Correction'
                ELSE 'Adjustment'
            END AS type,
            CAST(fa.previous_value AS CHAR) AS previous,
            CAST(fa.new_value AS CHAR) AS new_val,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS updated_by,
            'L' AS unit,
            fa.station_id
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.status = 'Approved'
    ) AS raw_upd
    WHERE station_id = ?
    ";
    $params = [$station_id];
} elseif ($active_tab === 'adjustments') {
    $union_sql = "
    SELECT 
        adjustment_no AS reference_no,
        item AS extra_1,
        previous AS supplier,
        new_val AS performed_by,
        reason AS status,
        manager AS extra_2,
        date_only AS date_time,
        station_id
    FROM (
        SELECT 
            CONCAT('ADJ-', il.id) AS adjustment_no,
            ip.product_name AS item,
            CAST(il.quantity_before AS CHAR) AS previous,
            CAST(il.quantity_after AS CHAR) AS new_val,
            il.notes AS reason,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS manager,
            il.created_at AS date_only,
            il.station_id
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.action = 'adjustment'

        UNION ALL

        SELECT 
            CONCAT('FADJ-', fa.id) AS adjustment_no,
            fa.fuel_type AS item,
            CAST(fa.previous_value AS CHAR) AS previous,
            CAST(fa.new_value AS CHAR) AS new_val,
            fa.reason AS reason,
            COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') AS manager,
            fa.created_at AS date_only,
            fa.station_id
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.status = 'Approved'
    ) AS raw_adj
    WHERE station_id = ?
    ";
    $params = [$station_id];
}

// ── OUTER WHERE BUILDER ───────────────────────────────────────
$outer_where = " WHERE 1=1 ";
$outer_params = [];

if ($search !== '') {
    if ($active_tab === 'updates') {
        $outer_where .= " AND (reference_no LIKE ? OR performed_by LIKE ? OR status LIKE ?)";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
    } elseif ($active_tab === 'adjustments') {
        $outer_where .= " AND (extra_1 LIKE ? OR supplier LIKE ? OR status LIKE ?)";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
    } elseif ($active_tab === 'deliveries') {
        $outer_where .= " AND (reference_no LIKE ? OR supplier LIKE ? OR performed_by LIKE ?)";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
    } else {
        $outer_where .= " AND (reference_no LIKE ? OR supplier LIKE ? OR performed_by LIKE ?)";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
        $outer_params[] = "%$search%";
    }
}
if ($ref_no !== '') {
    if ($active_tab === 'orders') {
        $outer_where .= " AND (reference_no LIKE ? OR extra_1 LIKE ?)";
    } elseif ($active_tab === 'deliveries') {
        $outer_where .= " AND (reference_no LIKE ? OR extra_1 LIKE ?)";
    } elseif ($active_tab === 'stock_in') {
        $outer_where .= " AND (reference_no LIKE ? OR extra_1 LIKE ?)";
    } else {
        $outer_where .= " AND reference_no LIKE ?";
    }
    $outer_params[] = "%$ref_no%";
    if (in_array($active_tab, ['orders', 'deliveries', 'stock_in'])) {
        $outer_params[] = "%$ref_no%";
    }
}
if ($start_date !== '' && $end_date !== '') {
    $outer_where .= " AND DATE(date_time) BETWEEN ? AND ?";
    $outer_params[] = $start_date;
    $outer_params[] = $end_date;
}
if ($move_type !== '' && $active_tab === 'updates') {
    $outer_where .= " AND extra_1 = ?";
    $outer_params[] = $move_type;
}
if ($status !== '') {
    $outer_where .= " AND LOWER(status) LIKE ?";
    $outer_params[] = "%" . strtolower($status) . "%";
}

$full_sql = "SELECT * FROM (" . $union_sql . ") AS ledger " . $outer_where . " ORDER BY date_time DESC";
$all_params = array_merge($params, $outer_params);

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
        'orders' => 'Purchase Orders Report',
        'deliveries' => 'Deliveries Report',
        'stock_in' => 'Stock-In Report',
        'updates' => 'Inventory Updates Report',
        'adjustments' => 'Inventory Adjustments Report'
    ];
    $title = $tab_labels[$active_tab] ?? 'Inventory History';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title><?= htmlspecialchars($title) ?> Report - <?= htmlspecialchars($station_name) ?></title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; font-size: 11px; color: #333; }
            .header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #002F70; padding-bottom: 10px; }
            .header h1 { margin: 0; color: #002F70; font-size: 18px; text-transform: uppercase; }
            .header p { margin: 3px 0; color: #555; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #cbd5e1; padding: 7px 9px; text-align: left; }
            th { background-color: #002F70; color: white; font-weight: bold; text-transform: uppercase; font-size: 9px; }
            tr:nth-child(even) { background-color: #f8fafc; }
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
                <?php if ($active_tab === 'orders'): ?>
                    <tr>
                        <th>PO No.</th>
                        <th>PR No.</th>
                        <th>Supplier</th>
                        <th>Generated By</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                <?php elseif ($active_tab === 'deliveries'): ?>
                    <tr>
                        <th>Delivery No.</th>
                        <th>PO No.</th>
                        <th>Supplier</th>
                        <th>Recorded By</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                <?php elseif ($active_tab === 'stock_in'): ?>
                    <tr>
                        <th>Stock-In No.</th>
                        <th>PO No.</th>
                        <th>Approved By</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                <?php elseif ($active_tab === 'updates'): ?>
                    <tr>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Previous</th>
                        <th>New</th>
                        <th>Updated By</th>
                    </tr>
                <?php elseif ($active_tab === 'adjustments'): ?>
                    <tr>
                        <th>Adjustment No.</th>
                        <th>Item</th>
                        <th>Previous</th>
                        <th>New</th>
                        <th>Reason</th>
                        <th>Manager</th>
                        <th>Date</th>
                    </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" style="text-align:center;">No history records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php if ($active_tab === 'orders'): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                                <td><?= htmlspecialchars($r['extra_1']) ?></td>
                                <td><?= htmlspecialchars($r['supplier']) ?></td>
                                <td><?= htmlspecialchars($r['performed_by']) ?></td>
                                <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                            </tr>
                        <?php elseif ($active_tab === 'deliveries'): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                                <td><code><?= htmlspecialchars($r['extra_1']) ?></code></td>
                                <td><?= htmlspecialchars($r['supplier']) ?></td>
                                <td><?= htmlspecialchars($r['performed_by']) ?></td>
                                <td><?= date('M d, Y', strtotime($r['date_time'])) ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                            </tr>
                        <?php elseif ($active_tab === 'stock_in'): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                                <td><code><?= htmlspecialchars($r['extra_1']) ?></code></td>
                                <td><?= htmlspecialchars($r['performed_by']) ?></td>
                                <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                            </tr>
                        <?php elseif ($active_tab === 'updates'): ?>
                            <tr>
                                <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                                <td><strong><?= htmlspecialchars($r['reference_no']) ?></strong></td>
                                <td><?= htmlspecialchars($r['extra_1']) ?></td>
                                <td><?= htmlspecialchars($r['supplier']) ?> <?= htmlspecialchars($r['extra_2']) ?></td>
                                <td><?= htmlspecialchars($r['performed_by']) ?> <?= htmlspecialchars($r['extra_2']) ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                            </tr>
                        <?php elseif ($active_tab === 'adjustments'): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                                <td><strong><?= htmlspecialchars($r['extra_1']) ?></strong></td>
                                <td><?= htmlspecialchars($r['supplier']) ?></td>
                                <td><?= htmlspecialchars($r['performed_by']) ?></td>
                                <td><?= htmlspecialchars($r['status']) ?></td>
                                <td><?= htmlspecialchars($r['extra_2']) ?></td>
                                <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                            </tr>
                        <?php endif; ?>
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
        
        if ($active_tab === 'orders') {
            fputcsv($out, ['PO No.', 'PR No.', 'Supplier', 'Generated By', 'Date', 'Status']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['reference_no'], $r['extra_1'], $r['supplier'], $r['performed_by'], $r['date_time'], $r['status']
                ]);
            }
        } elseif ($active_tab === 'deliveries') {
            fputcsv($out, ['Delivery No.', 'PO No.', 'Supplier', 'Recorded By', 'Date', 'Status']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['reference_no'], $r['extra_1'], $r['supplier'], $r['performed_by'], $r['date_time'], $r['status']
                ]);
            }
        } elseif ($active_tab === 'stock_in') {
            fputcsv($out, ['Stock-In No.', 'PO No.', 'Approved By', 'Date', 'Status']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['reference_no'], $r['extra_1'], $r['performed_by'], $r['date_time'], $r['status']
                ]);
            }
        } elseif ($active_tab === 'updates') {
            fputcsv($out, ['Date', 'Item', 'Type', 'Previous', 'New', 'Updated By']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date_time'], $r['reference_no'], $r['extra_1'], $r['supplier'], $r['performed_by'], $r['status']
                ]);
            }
        } elseif ($active_tab === 'adjustments') {
            fputcsv($out, ['Adjustment No.', 'Item', 'Previous', 'New', 'Reason', 'Manager', 'Date']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['reference_no'], $r['extra_1'], $r['supplier'], $r['performed_by'], $r['status'], $r['extra_2'], $r['date_time']
                ]);
            }
        }
        fclose($out);
        exit;
    }
}

// ── Calculate Dynamic KPI Cards ────────────────────────────────
$kpis = ['total' => count($rows), 'k1' => 0, 'k2' => 0, 'k3' => 0];
if ($active_tab === 'orders') {
    $kpi_labels = ['Total Orders', 'Pending Orders', 'Approved Orders', 'Completed Orders'];
    foreach ($rows as $r) {
        $st = strtolower($r['status']);
        if (strpos($st, 'pending') !== false || strpos($st, 'validation') !== false) $kpis['k1']++;
        elseif (strpos($st, 'approved') !== false || strpos($st, 'finalized') !== false || strpos($st, 'official') !== false) $kpis['k2']++;
        elseif (strpos($st, 'complete') !== false || strpos($st, 'delivered') !== false) $kpis['k3']++;
    }
} elseif ($active_tab === 'deliveries') {
    $kpi_labels = ['Total Deliveries', 'Expected Deliveries', 'Delivered / Completed', 'Returned / Short'];
    foreach ($rows as $r) {
        $st = strtolower($r['status']);
        if (strpos($st, 'expected') !== false || strpos($st, 'pending') !== false) $kpis['k1']++;
        elseif (strpos($st, 'completed') !== false || strpos($st, 'delivered') !== false || strpos($st, 'finalized') !== false) $kpis['k2']++;
        elseif (strpos($st, 'short') !== false || strpos($st, 'damaged') !== false || strpos($st, 'return') !== false) $kpis['k3']++;
    }
} elseif ($active_tab === 'stock_in') {
    $kpi_labels = ['Total Stock-Ins', 'Merchandise Stock-Ins', 'Fuel Stock-Ins', 'Others'];
    foreach ($rows as $r) {
        if (strpos($r['reference_no'], 'MDR-') !== false || strpos($r['reference_no'], 'SI-') !== false) $kpis['k1']++;
        elseif (strpos($r['reference_no'], 'FDR-') !== false || strpos($r['reference_no'], 'SIF-') !== false) $kpis['k2']++;
        else $kpis['k3']++;
    }
} elseif ($active_tab === 'updates') {
    $kpi_labels = ['Total Movements', 'Stock-In Logs', 'Stock-Out Logs', 'Adjustments / Corrections'];
    foreach ($rows as $r) {
        $ty = strtolower($r['extra_1']);
        if ($ty === 'stock-in') $kpis['k1']++;
        elseif ($ty === 'stock-out') $kpis['k2']++;
        elseif ($ty === 'adjustment' || $ty === 'correction') $kpis['k3']++;
    }
} elseif ($active_tab === 'adjustments') {
    $kpi_labels = ['Total Adjustments', 'Merchandise Adjustments', 'Fuel Adjustments', 'System Adjustments'];
    foreach ($rows as $r) {
        if (strpos($r['reference_no'], 'FADJ-') !== false) $kpis['k2']++;
        elseif (strpos($r['reference_no'], 'ADJ-') !== false) $kpis['k1']++;
        else $kpis['k3']++;
    }
}

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
.filter-grid input, .filter-grid select { 
    padding:8px 10px; 
    border:1px solid #cbd5e1; 
    border-radius:6px; 
    font-size:12.5px; 
    outline:none; 
    color:#334155; 
    height:36px; 
    box-sizing:border-box;
    pointer-events: auto !important;
    position: relative !important;
    z-index: 100 !important;
    cursor: pointer !important;
}
.filter-grid input:focus, .filter-grid select:focus { border-color:#002F70; }

/* Table design */
.po-table-wrap { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow-x:auto;-webkit-overflow-scrolling:touch; border:1px solid #e2e8f0; }
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

/* Clean Outlined Action Buttons */
.btn-outline {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 6px 12px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border: 1px solid #002F6C !important;
    transition: all 0.2s !important;
    background: white !important;
    color: #002F6C !important;
    height: 30px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-decoration: none !important;
}
.btn-outline:hover {
    background: #002F6C !important;
    color: white !important;
}
.btn-outline.btn-print {
    border-color: #4b5563 !important;
    color: #4b5563 !important;
}
.btn-outline.btn-print:hover {
    background: #4b5563 !important;
    color: white !important;
}
.btn-outline.btn-pdf {
    border-color: #dc2626 !important;
    color: #dc2626 !important;
}
.btn-outline.btn-pdf:hover {
    background: #dc2626 !important;
    color: white !important;
}

/* Centered Modals design */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); padding:24px; position:relative; }
.modal-box h3 {
    margin: -24px -24px 16px -24px;
    font-size: 15px;
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
.modal-foot { display:flex; justify-content:flex-end; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:12px; }

/* Custom Modal Table */
.modal-table { width:100%; border-collapse:collapse; margin-top:10px; font-size:12px; }
.modal-table th { background:#f8fafc; color:#475569; font-weight:700; padding:10px; border-bottom:2px solid #e2e8f0; text-transform:uppercase; font-size:10px; }
.modal-table td { padding:10px; border-bottom:1px solid #f1f5f9; color:#334155; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-history"></i> Inventory History Oversight</h1>
        <div class="sub">Monitor purchase orders, deliveries, stock-ins, updates, and adjustments &middot; Today: <?= date('F d, Y') ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="flt-btn flt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="flt-btn flt-btn-csv" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <button class="flt-btn flt-btn-pdf" onclick="exportInventoryHistoryPdf()" title="Export PDF">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button class="flt-btn flt-btn-print" onclick="printInventoryHistory()" title="Print">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- History Sub-Tabs -->
<div class="tab-nav">
    <a href="?tab=orders" class="tab-btn <?= $active_tab === 'orders' ? 'active' : '' ?>">
        <i class="fas fa-file-invoice-dollar"></i> Purchase Orders
    </a>
    <a href="?tab=deliveries" class="tab-btn <?= $active_tab === 'deliveries' ? 'active' : '' ?>">
        <i class="fas fa-shipping-fast"></i> Deliveries
    </a>
    <a href="?tab=stock_in" class="tab-btn <?= $active_tab === 'stock_in' ? 'active' : '' ?>">
        <i class="fas fa-boxes"></i> Stock-In
    </a>
    <a href="?tab=updates" class="tab-btn <?= $active_tab === 'updates' ? 'active' : '' ?>">
        <i class="fas fa-sync-alt"></i> Inventory Updates
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
                <label>Search Keyword</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search product / ref...">
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
            
            <?php if ($active_tab === 'updates'): ?>
                <div class="fg">
                    <label>Movement Type</label>
                    <select name="move_type">
                        <option value="">All Types</option>
                        <option value="Stock-In" <?= $move_type === 'Stock-In' ? 'selected' : '' ?>>Stock-In</option>
                        <option value="Stock-Out" <?= $move_type === 'Stock-Out' ? 'selected' : '' ?>>Stock-Out</option>
                        <option value="Adjustment" <?= $move_type === 'Adjustment' ? 'selected' : '' ?>>Adjustment</option>
                        <option value="Correction" <?= $move_type === 'Correction' ? 'selected' : '' ?>>Correction</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="fg">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php if ($active_tab === 'deliveries'): ?>
                        <option value="Expected Delivery" <?= $status === 'Expected Delivery' ? 'selected' : '' ?>>Expected Delivery</option>
                        <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Delivered" <?= $status === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="Completed" <?= $status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Stock-In Complete" <?= $status === 'Stock-In Complete' ? 'selected' : '' ?>>Stock-In Complete</option>
                        <option value="Finalized" <?= $status === 'Finalized' ? 'selected' : '' ?>>Finalized</option>
                    <?php else: ?>
                        <option value="Completed" <?= $status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Official" <?= $status === 'Official' ? 'selected' : '' ?>>Official</option>
                        <option value="Admin Finalized" <?= $status === 'Admin Finalized' ? 'selected' : '' ?>>Admin Finalized</option>
                        <option value="Expected Delivery" <?= $status === 'Expected Delivery' ? 'selected' : '' ?>>Expected Delivery</option>
                    <?php endif; ?>
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
            <span><?= number_format($kpis['k1']) ?></span>
        </div>
        <i class="fas fa-check-circle"></i>
    </div>
    <div class="sm-card yellow">
        <div class="det">
            <span><?= htmlspecialchars($kpi_labels[2]) ?></span>
            <span><?= number_format($kpis['k2']) ?></span>
        </div>
        <i class="fas fa-exchange-alt"></i>
    </div>
    <div class="sm-card red">
        <div class="det">
            <span><?= htmlspecialchars($kpi_labels[3]) ?></span>
            <span><?= number_format($kpis['k3']) ?></span>
        </div>
        <i class="fas fa-exclamation-triangle"></i>
    </div>
</div>

<!-- Ledger Table -->
<div class="po-table-wrap">
    <table class="po-table">
        <thead>
            <?php if ($active_tab === 'orders'): ?>
                <tr>
                    <th>PO No.</th>
                    <th>PR No.</th>
                    <th>Supplier</th>
                    <th>Generated By</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="text-align: center; width: 280px;">Action</th>
                </tr>
            <?php elseif ($active_tab === 'deliveries'): ?>
                <tr>
                    <th>Delivery No.</th>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Recorded By</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            <?php elseif ($active_tab === 'stock_in'): ?>
                <tr>
                    <th>Stock-In No.</th>
                    <th>PO No.</th>
                    <th>Approved By</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            <?php elseif ($active_tab === 'updates'): ?>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th class="right">Previous</th>
                    <th class="right">New</th>
                    <th>Updated By</th>
                </tr>
            <?php elseif ($active_tab === 'adjustments'): ?>
                <tr>
                    <th>Adjustment No.</th>
                    <th>Item</th>
                    <th class="right">Previous</th>
                    <th class="right">New</th>
                    <th>Reason</th>
                    <th>Manager</th>
                    <th>Date</th>
                </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 24px; color: #64748b;">
                        <i class="fas fa-history" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.3;"></i>
                        No history records found matching current criteria.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php if ($active_tab === 'orders'): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                            <td><?= htmlspecialchars($r['extra_1']) ?></td>
                            <td><?= htmlspecialchars($r['supplier']) ?></td>
                            <td><?= htmlspecialchars($r['performed_by']) ?></td>
                            <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                            <td>
                                <?php 
                                $status_class = 'badge-other';
                                $st = strtolower($r['status']);
                                if ($st === 'completed' || $st === 'approved' || $st === 'official' || $st === 'verified' || $st === 'admin finalized') {
                                    $status_class = 'badge-delivery';
                                } elseif ($st === 'pending' || strpos($st, 'validation') !== false) {
                                    $status_class = 'badge-adjustment';
                                }
                                ?>
                                <span class="badge <?= $status_class ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; flex-direction: column; gap: 6px; align-items: center;">
                                    <button class="btn-outline" onclick="viewPO('<?= htmlspecialchars($r['reference_no']) ?>', '<?= htmlspecialchars($r['extra_2']) ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <a href="print_po_new.php?batch_id=<?= urlencode($r['reference_no']) ?>&type=<?= urlencode($r['extra_2']) ?>&print=1" target="_blank" class="btn-outline btn-print">
                                        <i class="fas fa-print"></i> Print PO
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php elseif ($active_tab === 'deliveries'): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                            <td><code><?= htmlspecialchars($r['extra_1']) ?></code></td>
                            <td><?= htmlspecialchars($r['supplier']) ?></td>
                            <td><?= htmlspecialchars($r['performed_by']) ?></td>
                            <td><?= date('M d, Y', strtotime($r['date_time'])) ?></td>
                            <td>
                                <?php 
                                $status_class = 'badge-other';
                                $st = strtolower($r['status']);
                                if ($st === 'completed' || $st === 'delivered' || $st === 'finalized' || $st === 'stock-in complete') {
                                    $status_class = 'badge-delivery';
                                } elseif ($st === 'expected delivery' || $st === 'pending') {
                                    $status_class = 'badge-adjustment';
                                }
                                ?>
                                <span class="badge <?= $status_class ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                        </tr>
                    <?php elseif ($active_tab === 'stock_in'): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                            <td><code><?= htmlspecialchars($r['extra_1']) ?></code></td>
                            <td><?= htmlspecialchars($r['performed_by']) ?></td>
                            <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                            <td><span class="badge badge-delivery"><?= htmlspecialchars($r['status']) ?></span></td>
                            <td style="text-align: center;">
                                <a href="print_supplier_invoice.php?batch_id=<?= urlencode($r['reference_no']) ?>&print=1" target="_blank" class="btn-outline" style="background:#16a34a !important; color:#fff !important; border-color:#16a34a !important;">
                                    <i class="fas fa-file-invoice-dollar"></i> Print Invoice
                                </a>
                            </td>
                        </tr>
                    <?php elseif ($active_tab === 'updates'): ?>
                        <tr>
                            <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                            <td><strong><?= htmlspecialchars($r['reference_no']) ?></strong></td>
                            <td><span class="badge badge-other"><?= htmlspecialchars($r['extra_1']) ?></span></td>
                            <td class="right">
                                <?php 
                                $prev = $r['supplier'];
                                if ($prev === '—' || $prev === '') echo '—';
                                else echo number_format((float)$prev, 2) . ' ' . $r['extra_2'];
                                ?>
                            </td>
                            <td class="right" style="font-weight: 700;">
                                <?php 
                                $new_val = $r['performed_by'];
                                if ($new_val === '—' || $new_val === '') echo '—';
                                else echo number_format((float)$new_val, 2) . ' ' . $r['extra_2'];
                                ?>
                            </td>
                            <td><?= htmlspecialchars($r['status']) ?></td>
                        </tr>
                    <?php elseif ($active_tab === 'adjustments'): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($r['reference_no']) ?></code></td>
                            <td><strong><?= htmlspecialchars($r['extra_1']) ?></strong></td>
                            <td class="right"><?= number_format((float)$r['supplier'], 2) ?></td>
                            <td class="right" style="font-weight: 700;"><?= number_format((float)$r['performed_by'], 2) ?></td>
                            <td><?= htmlspecialchars($r['status']) ?></td>
                            <td><?= htmlspecialchars($r['extra_2']) ?></td>
                            <td><?= date('M d, Y g:i A', strtotime($r['date_time'])) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Purchase Order Details Modal -->
<div id="poModal" class="modal-overlay">
    <div class="modal-box">
        <h3><i class="fas fa-file-invoice"></i> Purchase Order Details</h3>
        
        <div style="margin-bottom:15px; font-size:12px; color:#475569;">
            <strong>PO Number:</strong> <span id="modalPoNo">—</span><br>
            <strong>Type:</strong> <span id="modalPoType">—</span>
        </div>
        
        <table class="modal-table">
            <thead>
                <tr>
                    <th>Product / Fuel</th>
                    <th style="text-align: right;">Quantity</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: right;">Total Amount</th>
                </tr>
            </thead>
            <tbody id="poModalTableBody">
                <tr><td colspan="4" style="text-align:center;">Loading...</td></tr>
            </tbody>
        </table>
        
        <div class="modal-foot">
            <button onclick="closePoModal()" class="flt-btn flt-btn-reset">Close</button>
        </div>
    </div>
</div>

<script>
function showPoModal() {
    document.getElementById('poModal').classList.add('open');
}

function closePoModal() {
    document.getElementById('poModal').classList.remove('open');
}

function viewPO(poNo, type) {
    document.getElementById('modalPoNo').innerText = poNo;
    document.getElementById('modalPoType').innerText = type === 'fuel' ? 'Fuel PO' : 'Merchandise PO';
    
    const tbody = document.getElementById('poModalTableBody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>';
    showPoModal();
    
    fetch(`?action=get_po_details&po_no=${encodeURIComponent(poNo)}&type=${type}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error: ${res.error}</td></tr>`;
                return;
            }
            if (res.items.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">No items found in this PO.</td></tr>`;
                return;
            }
            tbody.innerHTML = res.items.map(item => `
                <tr>
                    <td><strong>${item.product_name}</strong></td>
                    <td style="text-align: right;">${parseFloat(item.quantity).toLocaleString()} ${item.unit}</td>
                    <td style="text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                    <td style="text-align: right;">₱${parseFloat(item.total_amount).toFixed(2)}</td>
                </tr>
            `).join('');
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error: Failed to load details.</td></tr>`;
        });
}

function exportInventoryHistoryPdf() {
    const activeTab = '<?= $active_tab ?>';
    const startDate = '<?= htmlspecialchars($start_date) ?>';
    const endDate = '<?= htmlspecialchars($end_date) ?>';
    const filename = `admin_inventory_history_${activeTab}_${startDate}_to_${endDate}`;
    exportPrintableAreaToPDF('.po-table-wrap', 'Inventory History Oversight', filename, document.activeElement);
}

function printInventoryHistory() {
    window.print();
}

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            closePoModal();
        }
    });
});
</script>

<script>
// Force enable all filter inputs and dropdowns to be clickable
document.addEventListener('DOMContentLoaded', function() {
    forceEnableFilters();
});

// Also try after short delays
setTimeout(forceEnableFilters, 100);
setTimeout(forceEnableFilters, 500);

function forceEnableFilters() {
    // Enable all filter inputs and selects
    const filterInputs = document.querySelectorAll('.filter-grid input, .filter-grid select');
    filterInputs.forEach(elem => {
        elem.style.pointerEvents = 'auto';
        elem.style.zIndex = '100';
        elem.style.position = 'relative';
        elem.style.cursor = 'pointer';
    });
    
    // Enable filter buttons
    const filterButtons = document.querySelectorAll('.flt-btn');
    filterButtons.forEach(btn => {
        btn.style.pointerEvents = 'auto';
        btn.style.zIndex = '100';
        btn.style.cursor = 'pointer';
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
