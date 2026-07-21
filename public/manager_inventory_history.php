<?php
// ============================================================
// Manager Inventory History - manager_inventory_history.php
// Provides a unified, professional operation history interface for Managers.
// Supports 5 required tabs: Purchase Requests, Deliveries, Stock-In,
// Inventory Updates, and Inventory Adjustments (Read Only).
// Includes search, date filtering, detailed view modals, and printable stock-in view.
// ============================================================
$page_id = 'mgr_inv_movement';
$page_title = 'Inventory History';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// Ensure merchandise_stock_in table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_stock_in (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        po_id          INT NULL,
        po_number      VARCHAR(100) NULL,
        station_id     INT NOT NULL,
        product_id     INT NOT NULL,
        product_name   VARCHAR(255) NOT NULL,
        sku            VARCHAR(100) NULL,
        category       VARCHAR(100) NULL,
        qty_ordered    INT NOT NULL DEFAULT 0,
        qty_received   INT NOT NULL DEFAULT 0,
        qty_variance   INT NOT NULL DEFAULT 0,
        unit_cost      DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_cost     DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks        TEXT NULL,
        stock_before   INT NOT NULL DEFAULT 0,
        stock_after    INT NOT NULL DEFAULT 0,
        encoded_by     INT NOT NULL,
        encoded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref      VARCHAR(100) NULL,
        INDEX idx_station    (station_id),
        INDEX idx_encoded_at (encoded_at),
        INDEX idx_po_id      (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Ensure fuel_stock_in table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_in (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id    INT NOT NULL,
        invoice_no     VARCHAR(100) NULL,
        station_id     INT NOT NULL,
        fuel_type      VARCHAR(255) NOT NULL,
        qty_expected   DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty_received   DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty_variance   DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks        TEXT NULL,
        level_before   DECIMAL(12,2) NOT NULL DEFAULT 0,
        level_after    DECIMAL(12,2) NOT NULL DEFAULT 0,
        encoded_by     INT NOT NULL,
        encoded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref      VARCHAR(100) NULL,
        delivery_ref   VARCHAR(100) NULL,
        INDEX idx_station (station_id),
        INDEX idx_encoded_at (encoded_at),
        INDEX idx_delivery_id (delivery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}


// Access Control
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

if (isset($_GET['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    try {
        if ($action === 'get_pr_details') {
            $id = (int)($_GET['id'] ?? 0);
            $type = $_GET['type'] ?? 'merch';
            
            if ($type === 'merch') {
                // Fetch Merchandise PO items
                $stmt = $pdo->prepare("
                    SELECT poi.item_name AS product_name, poi.quantity, poi.unit_price, poi.total_price, COALESCE(p.size, 'pcs') AS unit
                    FROM purchase_order_items poi
                    LEFT JOIN inventory_products p ON poi.product_id = p.id
                    WHERE poi.po_id = ?
                ");
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fallback to purchase_orders directly if purchase_order_items is empty
                if (empty($items)) {
                    $stmt = $pdo->prepare("
                        SELECT product_name, quantity, unit_price, total_amount AS total_price, 'pcs' AS unit
                        FROM purchase_orders
                        WHERE id = ?
                    ");
                    $stmt->execute([$id]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                echo json_encode(['success' => true, 'items' => $items, 'is_fuel' => false]);
            } else {
                // Fetch Fuel PO details
                $stmt = $pdo->prepare("
                    SELECT fpo.po_number, ft.name AS fuel_type, fpo.volume AS quantity, fpo.unit_price, fpo.total_amount AS total_price, fpo.notes
                    FROM fuel_purchase_orders fpo
                    LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                    WHERE fpo.id = ?
                ");
                $stmt->execute([$id]);
                $fuel_po = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'items' => [$fuel_po], 'is_fuel' => true]);
            }
            exit;
        }
        
        if ($action === 'get_delivery_details') {
            $delivery_ref = trim($_GET['delivery_ref'] ?? '');
            $stmt = $pdo->prepare("
                SELECT product, expected_quantity, actual_quantity, damaged_quantity, COALESCE(unit, 'pcs') AS unit, remarks
                FROM deliveries_oversight
                WHERE delivery_ref = ? AND station_id = ?
            ");
            $stmt->execute([$delivery_ref, $station_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'items' => $items]);
            exit;
        }
        
        if ($action === 'get_stock_in_details') {
            $delivery_ref = trim($_GET['delivery_ref'] ?? '');
            
            // Check merchandise stock ins
            $stmt = $pdo->prepare("
                SELECT product_name, qty_ordered AS expected_quantity, qty_received AS actual_quantity, qty_variance, condition_flag, remarks, stock_before, stock_after, 'pcs' AS unit
                FROM merchandise_stock_in
                WHERE (batch_ref = ? OR po_number = ?) AND station_id = ?
            ");
            $stmt->execute([$delivery_ref, $delivery_ref, $station_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If empty, check fuel stock ins
            if (empty($items)) {
                $stmt = $pdo->prepare("
                    SELECT fuel_type AS product_name, qty_expected AS expected_quantity, qty_received AS actual_quantity, qty_variance, condition_flag, remarks, level_before AS stock_before, level_after AS stock_after, 'L' AS unit
                    FROM fuel_stock_in
                    WHERE (batch_ref = ? OR delivery_ref = ?) AND station_id = ?
                ");
                $stmt->execute([$delivery_ref, $delivery_ref, $station_id]);
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

// ── PRINTABLE STOCK-IN VIEW ──────────────────────────────────────────────────
if (isset($_GET['print_stock_in'])) {
    $delivery_ref = trim($_GET['print_stock_in']);
    
    // Fetch details of the stock in
    $stmt = $pdo->prepare("
        SELECT 
            do.delivery_ref, do.source_ref, do.supplier, do.manager_action_at, u.name AS approved_by, do.delivery_type
        FROM deliveries_oversight do
        LEFT JOIN users u ON do.manager_id = u.id
        WHERE do.delivery_ref = ? AND do.station_id = ? AND do.status = 'Stock-In Complete'
        LIMIT 1
    ");
    $stmt->execute([$delivery_ref, $station_id]);
    $header_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$header_info) {
        die("Error: Stock-In record not found.");
    }
    
    // Fetch items
    $items = [];
    if ($header_info['delivery_type'] === 'merchandise') {
        $stmt = $pdo->prepare("
            SELECT product_name, qty_ordered, qty_received, qty_variance, condition_flag, remarks, stock_before, stock_after, 'pcs' AS unit
            FROM merchandise_stock_in
            WHERE (batch_ref = ? OR po_number = ?) AND station_id = ?
        ");
        $stmt->execute([$delivery_ref, $delivery_ref, $station_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT fuel_type AS product_name, qty_expected AS qty_ordered, qty_received, qty_variance, condition_flag, remarks, level_before AS stock_before, level_after AS stock_after, 'L' AS unit
            FROM fuel_stock_in
            WHERE (batch_ref = ? OR delivery_ref = ?) AND station_id = ?
        ");
        $stmt->execute([$delivery_ref, $delivery_ref, $station_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Station Info
    $station_name = 'Petron Carmen';
    $station_address = 'Vamenta Blvd., Carmen, Cagayan de Oro';
    $st_stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
    $st_stmt->execute([$station_id]);
    $station = $st_stmt->fetch(PDO::FETCH_ASSOC);
    if ($station) {
        if (!empty($station['name'])) $station_name = $station['name'];
        if (!empty($station['address'])) $station_address = $station['address'];
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Stock-In Receipt - <?= htmlspecialchars($delivery_ref) ?></title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; padding: 30px; font-size: 13px; color: #333; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #002F6C; padding-bottom: 15px; position: relative; }
            .header h1 { margin: 0; color: #002F6C; font-size: 22px; text-transform: uppercase; font-weight: 800; }
            .header p { margin: 4px 0; color: #555; }
            .title-badge { font-weight: bold; background: #e2e8f0; padding: 4px 10px; border-radius: 4px; display: inline-block; margin-top: 10px; }
            .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
            .meta-item { background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
            .meta-item strong { color: #002F6C; display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #cbd5e1; padding: 10px 12px; text-align: left; }
            th { background-color: #002F6C; color: white; font-weight: bold; text-transform: uppercase; font-size: 11px; }
            tr:nth-child(even) { background-color: #f8fafc; }
            .right { text-align: right; }
            .center { text-align: center; }
            .footer { margin-top: 50px; text-align: center; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px; }
            @media print {
                body { padding: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="no-print" style="margin-bottom: 20px; display: flex; gap: 10px; justify-content: flex-end;">
            <button onclick="window.print()" style="padding: 8px 16px; background: #002F6C; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Print</button>
            <button onclick="window.close()" style="padding: 8px 16px; background: #cbd5e1; color: #333; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Close</button>
        </div>
        <div class="header">
            <h1><?= htmlspecialchars($station_name) ?></h1>
            <p><?= htmlspecialchars($station_address) ?></p>
            <div class="title-badge">INVENTORY STOCK-IN REPORT</div>
        </div>
        
        <div class="meta-grid">
            <div class="meta-item">
                <strong>Stock-In Reference No.</strong>
                <?= htmlspecialchars($header_info['delivery_ref']) ?>
            </div>
            <div class="meta-item">
                <strong>Purchase Order No.</strong>
                <?= htmlspecialchars($header_info['source_ref'] ?: '—') ?>
            </div>
            <div class="meta-item">
                <strong>Supplier</strong>
                <?= htmlspecialchars($header_info['supplier'] ?: '—') ?>
            </div>
            <div class="meta-item">
                <strong>Approved Date & Approver</strong>
                <?= date('M d, Y h:i A', strtotime($header_info['manager_action_at'])) ?> by <?= htmlspecialchars($header_info['approved_by'] ?: 'Manager') ?>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th class="right">Expected</th>
                    <th class="right">Received</th>
                    <th class="right">Variance</th>
                    <th class="center">Condition</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): 
                    $variance = $item['qty_received'] - $item['qty_ordered'];
                    $variance_str = $variance == 0 ? '0' : ($variance > 0 ? '+' . number_format($variance) : number_format($variance));
                    $variance_style = $variance < 0 ? 'color: #dc2626; font-weight: bold;' : ($variance > 0 ? 'color: #16a34a; font-weight: bold;' : '');
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
                        <td class="right"><?= number_format($item['qty_ordered']) ?> <?= htmlspecialchars($item['unit']) ?></td>
                        <td class="right"><?= number_format($item['qty_received']) ?> <?= htmlspecialchars($item['unit']) ?></td>
                        <td class="right" style="<?= $variance_style ?>"><?= $variance_str ?> <?= htmlspecialchars($item['unit']) ?></td>
                        <td class="center"><span style="font-weight: bold; color: <?= $item['condition_flag'] === 'Good' ? '#16a34a' : '#d97706' ?>;"><?= htmlspecialchars($item['condition_flag']) ?></span></td>
                        <td><?= htmlspecialchars($item['remarks'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="footer">
            Petron Station Management System • Generated on <?= date('M d, Y h:i A') ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── CORE DATA QUERYING & PROCESSING ──────────────────────────────────────────
$start_date = $_GET['start'] ?? '';
$end_date   = $_GET['end']   ?? '';
$search     = trim($_GET['search'] ?? '');
$active_tab = $_GET['tab'] ?? 'requests';

$rows = [];
$params = [];

if ($active_tab === 'requests') {
    // Tab 1: Purchase Requests
    $where = " WHERE pr_tbl.station_id = :station_id AND pr_tbl.created_by_id = :user_id ";
    $params = [':station_id' => $station_id, ':user_id' => $me['id']];
    if ($search !== '') {
        $where .= " AND (pr_tbl.pr_no LIKE :search OR pr_tbl.supplier LIKE :search OR pr_tbl.status LIKE :search) ";
        $params[':search'] = "%$search%";
    }
    if ($start_date !== '') {
        $where .= " AND DATE(pr_tbl.date) >= :start_date ";
        $params[':start_date'] = $start_date;
    }
    if ($end_date !== '') {
        $where .= " AND DATE(pr_tbl.date) <= :end_date ";
        $params[':end_date'] = $end_date;
    }
    
    $sql = "SELECT * FROM (
        SELECT 
            'merch' AS type_class,
            po.id,
            po.po_number AS pr_no,
            'Merchandise' AS type,
            u.name AS requested_by,
            s.name AS supplier,
            po.created_at AS date,
            po.status,
            po.total_amount,
            po.station_id,
            po.created_by AS created_by_id
        FROM purchase_orders po
        LEFT JOIN users u ON po.created_by = u.id
        LEFT JOIN suppliers s ON po.supplier_id = s.id

        UNION ALL

        SELECT 
            'fuel' AS type_class,
            fpo.id,
            fpo.po_number AS pr_no,
            'Fuel' AS type,
            u.name AS requested_by,
            s.name AS supplier,
            fpo.created_at AS date,
            fpo.status,
            fpo.total_amount,
            fpo.station_id,
            fpo.created_by AS created_by_id
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.created_by = u.id
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
    ) AS pr_tbl " . $where . " ORDER BY date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($active_tab === 'deliveries') {
    // Tab 2: Deliveries
    $where = " WHERE do.station_id = :station_id ";
    $params = [':station_id' => $station_id];
    if ($search !== '') {
        $where .= " AND (do.delivery_ref LIKE :search OR do.source_ref LIKE :search OR do.supplier LIKE :search OR u.name LIKE :search) ";
        $params[':search'] = "%$search%";
    }
    if ($start_date !== '') {
        $where .= " AND DATE(do.delivery_date) >= :start_date ";
        $params[':start_date'] = $start_date;
    }
    if ($end_date !== '') {
        $where .= " AND DATE(do.delivery_date) <= :end_date ";
        $params[':end_date'] = $end_date;
    }
    
    $sql = "SELECT 
                do.id,
                do.delivery_ref AS delivery_no,
                do.source_ref AS po_no,
                do.supplier,
                u.name AS received_by,
                do.delivery_date,
                do.status
            FROM deliveries_oversight do
            LEFT JOIN users u ON do.encoded_by = u.id
            " . $where . "
            GROUP BY do.delivery_ref
            ORDER BY do.delivery_date DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($active_tab === 'stock_in') {
    // Tab 3: Stock-In
    $where = " WHERE do.station_id = :station_id AND do.status = 'Stock-In Complete' ";
    $params = [':station_id' => $station_id];
    if ($search !== '') {
        $where .= " AND (do.delivery_ref LIKE :search OR do.source_ref LIKE :search OR do.supplier LIKE :search OR u.name LIKE :search) ";
        $params[':search'] = "%$search%";
    }
    if ($start_date !== '') {
        $where .= " AND DATE(do.manager_action_at) >= :start_date ";
        $params[':start_date'] = $start_date;
    }
    if ($end_date !== '') {
        $where .= " AND DATE(do.manager_action_at) <= :end_date ";
        $params[':end_date'] = $end_date;
    }
    
    $sql = "SELECT 
                do.id,
                do.delivery_ref AS stock_in_no,
                do.source_ref AS po_no,
                do.supplier,
                u.name AS approved_by,
                do.manager_action_at AS date,
                'Approved' AS status,
                do.delivery_ref
            FROM deliveries_oversight do
            LEFT JOIN users u ON do.manager_id = u.id
            " . $where . "
            GROUP BY do.delivery_ref
            ORDER BY do.manager_action_at DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($active_tab === 'updates') {
    // Tab 4: Inventory Updates (Automatic history after Stock-In)
    $where = " WHERE updates_tbl.station_id = :station_id ";
    $params = [':station_id' => $station_id];
    if ($search !== '') {
        $where .= " AND (updates_tbl.product_fuel LIKE :search OR updates_tbl.updated_by LIKE :search) ";
        $params[':search'] = "%$search%";
    }
    if ($start_date !== '') {
        $where .= " AND DATE(updates_tbl.date) >= :start_date ";
        $params[':start_date'] = $start_date;
    }
    if ($end_date !== '') {
        $where .= " AND DATE(updates_tbl.date) <= :end_date ";
        $params[':end_date'] = $end_date;
    }
    
    $sql = "SELECT * FROM (
        SELECT 
            il.created_at AS date,
            ip.product_name AS product_fuel,
            'Stock-In' AS transaction,
            CONCAT(FORMAT(il.quantity_before, 0), ' ', COALESCE(si.unit, 'pcs')) AS previous_stock,
            CONCAT(FORMAT(il.quantity_after, 0), ' ', COALESCE(si.unit, 'pcs')) AS new_stock,
            u.name AS updated_by,
            il.station_id
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN station_inventory si ON il.product_id = si.product_id AND si.station_id = il.station_id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.action IN ('delivery', 'stock_in')

        UNION ALL

        SELECT 
            fsi.encoded_at AS date,
            fsi.fuel_type AS product_fuel,
            'Stock-In' AS transaction,
            CONCAT(FORMAT(fsi.level_before, 0), ' L') AS previous_stock,
            CONCAT(FORMAT(fsi.level_after, 0), ' L') AS new_stock,
            u.name AS updated_by,
            fsi.station_id
        FROM fuel_stock_in fsi
        LEFT JOIN users u ON fsi.encoded_by = u.id
    ) AS updates_tbl " . $where . " ORDER BY date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($active_tab === 'adjustments') {
    // Tab 5: Inventory Adjustments (Read Only)
    $where = " WHERE adj_tbl.station_id = :station_id ";
    $params = [':station_id' => $station_id];
    if ($search !== '') {
        $where .= " AND (adj_tbl.item LIKE :search OR adj_tbl.reason LIKE :search OR adj_tbl.adjusted_by LIKE :search) ";
        $params[':search'] = "%$search%";
    }
    if ($start_date !== '') {
        $where .= " AND DATE(adj_tbl.date) >= :start_date ";
        $params[':start_date'] = $start_date;
    }
    if ($end_date !== '') {
        $where .= " AND DATE(adj_tbl.date) <= :end_date ";
        $params[':end_date'] = $end_date;
    }
    
    $sql = "SELECT * FROM (
        SELECT 
            CONCAT('ADJ-', il.id) AS adjustment_no,
            ip.product_name AS item,
            il.notes AS reason,
            CONCAT(FORMAT(il.quantity_before, 0), ' ', COALESCE(si.unit, 'pcs')) AS previous,
            CONCAT(FORMAT(il.quantity_after, 0), ' ', COALESCE(si.unit, 'pcs')) AS new_val,
            u.name AS adjusted_by,
            il.created_at AS date,
            il.station_id
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        LEFT JOIN station_inventory si ON il.product_id = si.product_id AND si.station_id = il.station_id
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.action = 'adjustment'

        UNION ALL

        SELECT 
            CONCAT('FADJ-', fa.id) AS adjustment_no,
            fa.fuel_type AS item,
            fa.reason AS reason,
            CONCAT(FORMAT(fa.previous_value, 0), ' L') AS previous,
            CONCAT(FORMAT(fa.new_value, 0), ' L') AS new_val,
            u.name AS adjusted_by,
            fa.created_at AS date,
            fa.station_id
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.status = 'Approved'
    ) AS adj_tbl " . $where . " ORDER BY date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ══ PETRON THEME AND COMPONENTS ══ */
.int-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    margin-top: 0px !important;
}
.int-head h1 {
    font-size: 20px;
    font-weight: 800;
    color: #002F6C;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.5px;
}
.int-head .sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

/* Tabs Layout */
.tab-nav {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 20px;
    overflow-x: auto;
}
.tab-btn {
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.tab-btn:hover {
    color: #002F6C;
}
.tab-btn.active {
    color: #002F6C;
    border-bottom-color: #002F6C;
}

/* Custom Table & Badges */
.table-wrap {
    overflow-x: auto;
    background: #fff;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-align: left;
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
}
.table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.table tr:hover {
    background: #f8fafc;
}

/* Badges */
.badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
}
.badge-pending { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.badge-approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-completed { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-cancelled { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
.badge-other { background: #f3f4f6; color: #1f2937; border: 1px solid #e5e7eb; }

/* Filter Container */
.filter-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.filter-bar .group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 150px;
}
.filter-bar label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
}
.filter-bar input, .filter-bar select {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s;
}
.filter-bar input:focus {
    border-color: #002F6C;
}

/* Buttons */
.btn-primary {
    background: #002F6C;
    color: white;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 13px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.2s;
}
.btn-primary:hover {
    background: #001e47;
}
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
    pointer-events: auto !important;
    border: 1px solid #002F6C !important;
    transition: all 0.2s !important;
    background: white !important;
    color: #002F6C !important;
    height: 30px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-decoration: none !important;
    position: relative !important;
    z-index: 999 !important;
}
.btn-outline:hover {
    background: #002F6C !important;
    color: white !important;
}

/* Centered Modals design */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.open { display:flex; }
.modal-box {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
    width: 600px;
    max-width: 95%;
    max-height: 90vh;
    overflow-y: auto;
    transform: translateY(20px);
    transition: transform 0.2s ease;
}
.modal-overlay.open .modal-box {
    transform: translateY(0);
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: #002F6C;
}
.modal-body {
    padding: 20px;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>

<!-- ══ Page Title / Header ══ -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-history"></i> Inventory History</h1>
    </div>
</div>

<!-- ══ Tab Navigation ══ -->
<div class="tab-nav">
    <a href="?tab=requests" class="tab-btn <?= $active_tab === 'requests' ? 'active' : '' ?>">
        <i class="fas fa-file-signature"></i> Purchase Requests
    </a>
    <a href="?tab=deliveries" class="tab-btn <?= $active_tab === 'deliveries' ? 'active' : '' ?>">
        <i class="fas fa-truck"></i> Deliveries
    </a>
    <a href="?tab=stock_in" class="tab-btn <?= $active_tab === 'stock_in' ? 'active' : '' ?>">
        <i class="fas fa-arrow-down-9-1"></i> Stock-In
    </a>
    <a href="?tab=updates" class="tab-btn <?= $active_tab === 'updates' ? 'active' : '' ?>">
        <i class="fas fa-sync-alt"></i> Inventory Updates
    </a>
    <a href="?tab=adjustments" class="tab-btn <?= $active_tab === 'adjustments' ? 'active' : '' ?>">
        <i class="fas fa-balance-scale"></i> Inventory Adjustments
    </a>
</div>

<!-- ══ Search & Filtering Bar ══ -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
    
    <div class="group">
        <label for="search">Keyword Search</label>
        <input type="text" name="search" id="search" placeholder="Search references, status..." value="<?= htmlspecialchars($search) ?>">
    </div>
    
    <div class="group">
        <label for="start">Start Date</label>
        <input type="date" name="start" id="start" value="<?= htmlspecialchars($start_date) ?>">
    </div>
    
    <div class="group">
        <label for="end">End Date</label>
        <input type="date" name="end" id="end" value="<?= htmlspecialchars($end_date) ?>">
    </div>
    
    <div style="display: flex; gap: 8px;">
        <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filter</button>
        <a href="?tab=<?= htmlspecialchars($active_tab) ?>" class="btn-outline"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<!-- ══ Main Content Table ══ -->
<div class="table-wrap">
    <table class="table">
        <?php if ($active_tab === 'requests'): ?>
            <thead>
                <tr>
                    <th>PR No.</th>
                    <th>Type</th>
                    <th>Requested By</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 24px;">No purchase requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): 
                        // Map status colors
                        $st = strtolower($r['status']);
                        $badge_class = 'badge-other';
                        $status_label = $r['status'];
                        if (in_array($st, ['draft', 'pending approval', 'pending'])) {
                            $badge_class = 'badge-pending';
                            $status_label = 'Pending PO';
                        } elseif (in_array($st, ['approved', 'approved po', 'admin finalized'])) {
                            $badge_class = 'badge-completed';
                            $status_label = 'PO Generated';
                        } elseif (in_array($st, ['confirmed', 'pending admin validation'])) {
                            $badge_class = 'badge-pending';
                            $status_label = 'Waiting Delivery';
                        } elseif (in_array($st, ['received', 'completed', 'official'])) {
                            $badge_class = 'badge-approved';
                            $status_label = 'Completed';
                        } elseif ($st === 'cancelled') {
                            $badge_class = 'badge-cancelled';
                        }
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['pr_no']) ?></strong></td>
                            <td><?= htmlspecialchars($r['type']) ?></td>
                            <td><?= htmlspecialchars($r['requested_by']) ?></td>
                            <td><?= htmlspecialchars($r['supplier'] ?: '—') ?></td>
                            <td><?= date('M d, Y h:i A', strtotime($r['date'])) ?></td>
                            <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($status_label) ?></span></td>
                            <td style="text-align: center;">
                                <button type="button" onclick="viewPR(<?= $r['id'] ?>, '<?= $r['type_class'] ?>')" class="btn-outline">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
        <?php elseif ($active_tab === 'deliveries'): ?>
            <thead>
                <tr>
                    <th>Delivery No.</th>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Received By</th>
                    <th>Delivery Date</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 24px;">No deliveries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['delivery_no']) ?></strong></td>
                            <td><code><?= htmlspecialchars($r['po_no'] ?: '—') ?></code></td>
                            <td><?= htmlspecialchars($r['supplier']) ?></td>
                            <td><?= htmlspecialchars($r['received_by'] ?: '—') ?></td>
                            <td><?= date('M d, Y', strtotime($r['delivery_date'])) ?></td>
                            <td>
                                <span class="badge <?= strtolower($r['status']) === 'stock-in complete' ? 'badge-approved' : 'badge-pending' ?>">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" onclick="viewDelivery('<?= htmlspecialchars($r['delivery_no']) ?>')" class="btn-outline">
                                    <i class="fas fa-eye"></i> View Delivery
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
        <?php elseif ($active_tab === 'stock_in'): ?>
            <thead>
                <tr>
                    <th>Stock-In No.</th>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Approved By</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 24px;">No stock-in records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['stock_in_no']) ?></strong></td>
                            <td><code><?= htmlspecialchars($r['po_no'] ?: '—') ?></code></td>
                            <td><?= htmlspecialchars($r['supplier']) ?></td>
                            <td><?= htmlspecialchars($r['approved_by'] ?: 'Manager') ?></td>
                            <td><?= date('M d, Y h:i A', strtotime($r['date'])) ?></td>
                            <td><span class="badge badge-approved">Approved</span></td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px; justify-content: center;">
                                    <button type="button" onclick="viewStockIn('<?= htmlspecialchars($r['stock_in_no']) ?>')" class="btn-outline">
                                        <i class="fas fa-eye"></i> View Details
                                    </button>
                                    <a href="?print_stock_in=<?= urlencode($r['stock_in_no']) ?>" target="_blank" class="btn-outline">
                                        <i class="fas fa-print"></i> Print Stock-In
                                    </a>
                                    <a href="print_supplier_invoice.php?batch_id=<?= urlencode($r['stock_in_no']) ?>&print=1" target="_blank" class="btn-outline" style="background:#16a34a !important; color:#fff !important; border-color:#16a34a !important;">
                                        <i class="fas fa-file-invoice-dollar"></i> Print Invoice
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
        <?php elseif ($active_tab === 'updates'): ?>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product/Fuel</th>
                    <th>Transaction</th>
                    <th style="text-align: right;">Previous</th>
                    <th style="text-align: right;">New Stock</th>
                    <th>Updated By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #64748b; padding: 24px;">No inventory updates found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= date('M d, Y h:i A', strtotime($r['date'])) ?></td>
                            <td><strong><?= htmlspecialchars($r['product_fuel']) ?></strong></td>
                            <td><span class="badge badge-completed"><?= htmlspecialchars($r['transaction']) ?></span></td>
                            <td style="text-align: right; color: #64748b;"><?= htmlspecialchars($r['previous_stock']) ?></td>
                            <td style="text-align: right; font-weight: bold; color: #002F6C;"><?= htmlspecialchars($r['new_stock']) ?></td>
                            <td><?= htmlspecialchars($r['updated_by'] ?: 'System') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
        <?php elseif ($active_tab === 'adjustments'): ?>
            <thead>
                <tr>
                    <th>Adjustment No.</th>
                    <th>Item</th>
                    <th>Reason</th>
                    <th style="text-align: right;">Previous</th>
                    <th style="text-align: right;">New</th>
                    <th>Adjusted By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 24px;">No inventory adjustments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($r['adjustment_no']) ?></code></td>
                            <td><strong><?= htmlspecialchars($r['item']) ?></strong></td>
                            <td><?= htmlspecialchars($r['reason'] ?: 'Manual Stock Adjustment') ?></td>
                            <td style="text-align: right; color: #64748b;"><?= htmlspecialchars($r['previous']) ?></td>
                            <td style="text-align: right; font-weight: bold; color: #d97706;"><?= htmlspecialchars($r['new_val']) ?></td>
                            <td><?= htmlspecialchars($r['adjusted_by'] ?: 'System') ?></td>
                            <td><?= date('M d, Y h:i A', strtotime($r['date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        <?php endif; ?>
    </table>
</div>

<!-- ══ DETAILS VIEW MODAL ══ -->
<div id="detailsModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Details</h3>
            <button type="button" onclick="closeModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <div class="modal-body">
            <table class="table" style="width: 100%;">
                <thead id="modalTableHead">
                    <!-- Dynamic -->
                </thead>
                <tbody id="modalTableBody">
                    <!-- Dynamic -->
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal()" class="btn-primary">Close</button>
        </div>
    </div>
</div>

<script>
function showModal() {
    document.getElementById('detailsModal').classList.add('open');
}
function closeModal() {
    document.getElementById('detailsModal').classList.remove('open');
}

function viewPR(id, type) {
    document.getElementById('modalTitle').innerText = 'Purchase Request Details';
    const tbody = document.getElementById('modalTableBody');
    const thead = document.getElementById('modalTableHead');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>';
    showModal();
    
    fetch(`?action=get_pr_details&id=${id}&type=${type}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error: ${res.error}</td></tr>`;
                return;
            }
            
            if (res.is_fuel) {
                thead.innerHTML = `
                    <tr>
                        <th>Fuel Type</th>
                        <th style="text-align: right;">Volume</th>
                        <th style="text-align: right;">Unit Price</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                `;
                tbody.innerHTML = res.items.map(item => `
                    <tr>
                        <td><strong>${item.fuel_type}</strong></td>
                        <td style="text-align: right;">${parseFloat(item.quantity).toLocaleString()} L</td>
                        <td style="text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="text-align: right;">₱${parseFloat(item.total_price).toFixed(2)}</td>
                    </tr>
                `).join('');
            } else {
                thead.innerHTML = `
                    <tr>
                        <th>Product</th>
                        <th style="text-align: right;">Quantity</th>
                        <th style="text-align: right;">Unit Price</th>
                        <th style="text-align: right;">Total Price</th>
                    </tr>
                `;
                tbody.innerHTML = res.items.map(item => `
                    <tr>
                        <td><strong>${item.product_name}</strong></td>
                        <td style="text-align: right;">${parseInt(item.quantity)} ${item.unit}</td>
                        <td style="text-align: right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="text-align: right;">₱${parseFloat(item.total_price).toFixed(2)}</td>
                    </tr>
                `).join('');
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error: Failed to load details.</td></tr>`;
        });
}

function viewDelivery(deliveryRef) {
    document.getElementById('modalTitle').innerText = `Delivery Details (${deliveryRef})`;
    const tbody = document.getElementById('modalTableBody');
    const thead = document.getElementById('modalTableHead');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Loading...</td></tr>';
    
    thead.innerHTML = `
        <tr>
            <th>Product / Item</th>
            <th style="text-align: right;">Expected</th>
            <th style="text-align: right;">Actual Rec.</th>
            <th style="text-align: right;">Damaged</th>
        </tr>
    `;
    showModal();
    
    fetch(`?action=get_delivery_details&delivery_ref=${encodeURIComponent(deliveryRef)}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error: ${res.error}</td></tr>`;
                return;
            }
            tbody.innerHTML = res.items.map(item => `
                <tr>
                    <td><strong>${item.product}</strong></td>
                    <td style="text-align: right;">${parseFloat(item.expected_quantity).toLocaleString()} ${item.unit}</td>
                    <td style="text-align: right;">${parseFloat(item.actual_quantity).toLocaleString()} ${item.unit}</td>
                    <td style="text-align: right; color: ${parseFloat(item.damaged_quantity) > 0 ? '#dc2626' : '#334155'};">${parseFloat(item.damaged_quantity).toLocaleString()} ${item.unit}</td>
                </tr>
            `).join('');
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:red;">Error: Failed to load details.</td></tr>`;
        });
}

function viewStockIn(deliveryRef) {
    document.getElementById('modalTitle').innerText = `Stock-In Details (${deliveryRef})`;
    const tbody = document.getElementById('modalTableBody');
    const thead = document.getElementById('modalTableHead');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading...</td></tr>';
    
    thead.innerHTML = `
        <tr>
            <th>Product / Item</th>
            <th style="text-align: right;">Expected</th>
            <th style="text-align: right;">Received</th>
            <th style="text-align: right;">Variance</th>
            <th>Condition</th>
        </tr>
    `;
    showModal();
    
    fetch(`?action=get_stock_in_details&delivery_ref=${encodeURIComponent(deliveryRef)}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:red;">Error: ${res.error}</td></tr>`;
                return;
            }
            tbody.innerHTML = res.items.map(item => {
                const expected = parseFloat(item.expected_quantity) || 0;
                const actual = parseFloat(item.actual_quantity) || 0;
                const variance = actual - expected;
                const varianceText = variance === 0 ? '0' : (variance > 0 ? '+' + variance.toLocaleString() : variance.toLocaleString());
                const varianceColor = variance < 0 ? '#dc2626' : (variance > 0 ? '#16a34a' : '#334155');
                return `
                    <tr>
                        <td><strong>${item.product_name}</strong></td>
                        <td style="text-align: right;">${expected.toLocaleString()} ${item.unit}</td>
                        <td style="text-align: right;">${actual.toLocaleString()} ${item.unit}</td>
                        <td style="text-align: right; color: ${varianceColor}; font-weight: bold;">${varianceText} ${item.unit}</td>
                        <td><span style="font-weight: bold; color: ${item.condition_flag === 'Good' ? '#16a34a' : '#d97706'}">${item.condition_flag}</span></td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:red;">Error: Failed to load details.</td></tr>`;
        });
}
</script>

<?php
include __DIR__ . '/../partials/footer.php';
?>
