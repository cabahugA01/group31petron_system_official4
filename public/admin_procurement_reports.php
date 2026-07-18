<?php
/**
 * Admin Procurement Reports
 * Purchase Orders | Delivery Receipts | PO vs Delivery | Stock-In Approvals
 */

$page_id = 'admin_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['admin', 'superadmin'], true)) {
    die('Access denied. Only administrators can view this page.');
}

$allowed_sections = ['po', 'delivery', 'comparison', 'stockin'];
$section = in_array($_GET['section'] ?? '', $allowed_sections, true) ? $_GET['section'] : 'po';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

function pr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pr_money($value): string
{
    return '&#8369;' . number_format((float)$value, 2);
}

function pr_qty($value): string
{
    $text = number_format((float)$value, 2);
    return rtrim(rtrim($text, '0'), '.');
}

function pr_date($value): string
{
    $ts = strtotime((string)$value);
    return $ts ? date('m/d/Y', $ts) : '-';
}

function pr_datetime($value): string
{
    $ts = strtotime((string)$value);
    return $ts ? date('m/d/Y H:i', $ts) : '-';
}

function pr_status_class($status): string
{
    $s = strtolower((string)$status);
    if (strpos($s, 'complete') !== false || strpos($s, 'received') !== false || strpos($s, 'delivered') !== false || strpos($s, 'stock-in') !== false || strpos($s, 'approved') !== false || strpos($s, 'good') !== false) {
        return 'ok';
    }
    if (strpos($s, 'partial') !== false || strpos($s, 'pending') !== false || strpos($s, 'expected') !== false || strpos($s, 'short') !== false) {
        return 'warn';
    }
    if (strpos($s, 'reject') !== false || strpos($s, 'cancel') !== false || strpos($s, 'damaged') !== false) {
        return 'bad';
    }
    return 'info';
}

function pr_compare_status(float $ordered, float $received, string $raw_status = ''): string
{
    if (stripos($raw_status, 'damaged') !== false) {
        return 'Damaged Items';
    }
    if ($received <= 0) {
        return 'Pending Delivery';
    }
    if ($received < $ordered) {
        return 'Partial Delivery';
    }
    if ($received > $ordered) {
        return 'Excess Delivery';
    }
    return 'Complete Delivery';
}

function pr_user_name_expr(string $alias): string
{
    return "COALESCE(NULLIF(TRIM(CONCAT(COALESCE({$alias}.first_name,''),' ',COALESCE({$alias}.last_name,''))),''), {$alias}.name, {$alias}.username, '-')";
}

$po_rows = [];
try {
    $user_name = pr_user_name_expr('u');
    $q = $pdo->prepare("
        SELECT
            'Merchandise' AS po_type,
            COALESCE(NULLIF(TRIM(po.batch_id), ''), po.po_number) AS po_number,
            COALESCE(sr.request_no, '-') AS pr_number,
            COALESCE(s.name, 'Petron Corporation') AS supplier_name,
            COALESCE(poi.item_name, po.product_name, '-') AS item_name,
            COALESCE(NULLIF(poi.quantity_ordered, 0), NULLIF(poi.quantity, 0), po.quantity, 0) AS ordered_qty,
            'pcs' AS unit,
            COALESCE(poi.unit_price, po.unit_price, 0) AS unit_price,
            COALESCE(poi.total_price, po.total_amount, 0) AS total_amount,
            po.status,
            po.expected_delivery_date,
            COALESCE(po.admin_finalized_at, po.created_at) AS po_date,
            {$user_name} AS generated_by
        FROM purchase_orders po
        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
        LEFT JOIN stock_requests sr ON sr.id = po.request_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN users u ON u.id = COALESCE(po.admin_id, po.created_by)
        WHERE po.station_id = ?
          AND DATE(COALESCE(po.admin_finalized_at, po.created_at)) BETWEEN ? AND ?

        UNION ALL

        SELECT
            'Fuel' AS po_type,
            COALESCE(NULLIF(TRIM(fpo.batch_id), ''), fpo.po_number) AS po_number,
            '-' AS pr_number,
            COALESCE(s.name, 'Petron Corporation') AS supplier_name,
            COALESCE(ft.name, 'Fuel') AS item_name,
            COALESCE(fpo.volume, 0) AS ordered_qty,
            'L' AS unit,
            COALESCE(fpo.unit_price, 0) AS unit_price,
            COALESCE(fpo.total_amount, 0) AS total_amount,
            fpo.status,
            fpo.expected_delivery_date,
            fpo.created_at AS po_date,
            {$user_name} AS generated_by
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
        LEFT JOIN suppliers s ON s.id = fpo.supplier_id
        LEFT JOIN users u ON u.id = fpo.created_by
        WHERE fpo.station_id = ?
          AND DATE(fpo.created_at) BETWEEN ? AND ?
        ORDER BY po_date DESC, po_number DESC, item_name ASC
    ");
    $q->execute([$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);
    $po_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $po_rows = [];
}

$delivery_rows = [];
try {
    $user_name = pr_user_name_expr('u');
    $q = $pdo->prepare("
        SELECT
            CASE WHEN do2.delivery_type = 'fuel' THEN 'Fuel' ELSE 'Merchandise' END AS delivery_type,
            COALESCE(NULLIF(do2.source_ref, ''), NULLIF(do2.batch_id, ''), '-') AS po_number,
            do2.delivery_ref,
            COALESCE(do2.dr_number, '-') AS dr_number,
            COALESCE(do2.sales_invoice_no, '-') AS sales_invoice_no,
            COALESCE(do2.supplier, '-') AS supplier_name,
            COALESCE(do2.product, '-') AS item_name,
            COALESCE(do2.expected_quantity, do2.quantity, 0) AS expected_qty,
            COALESCE(do2.actual_quantity, do2.quantity, 0) AS received_qty,
            COALESCE(do2.damaged_quantity, 0) AS damaged_qty,
            COALESCE(do2.unit, '') AS unit,
            COALESCE(do2.unit_price, do2.unit_cost, 0) AS unit_price,
            do2.delivery_date,
            do2.status,
            COALESCE(NULLIF(do2.received_by_name, ''), {$user_name}) AS received_by
        FROM deliveries_oversight do2
        LEFT JOIN users u ON u.id = do2.encoded_by
        WHERE do2.station_id = ?
          AND DATE(do2.delivery_date) BETWEEN ? AND ?
        ORDER BY do2.delivery_date DESC, do2.delivery_ref DESC, do2.id DESC
    ");
    $q->execute([$station_id, $date_from, $date_to]);
    $delivery_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $delivery_rows = [];
}

$comparison_rows = [];
try {
    $q = $pdo->prepare("
        SELECT
            po.po_type,
            po.po_number,
            po.supplier_name,
            po.item_name,
            po.ordered_qty,
            COALESCE(d.received_qty, 0) AS received_qty,
            po.unit,
            po.expected_delivery_date,
            d.latest_delivery_date,
            COALESCE(d.delivery_refs, '-') AS delivery_refs,
            COALESCE(d.delivery_status, '') AS delivery_status
        FROM (
            SELECT
                'Merchandise' AS po_type,
                COALESCE(NULLIF(TRIM(po.batch_id), ''), po.po_number) AS po_number,
                COALESCE(s.name, 'Petron Corporation') AS supplier_name,
                COALESCE(poi.item_name, po.product_name, '-') AS item_name,
                SUM(COALESCE(NULLIF(poi.quantity_ordered, 0), NULLIF(poi.quantity, 0), po.quantity, 0)) AS ordered_qty,
                'pcs' AS unit,
                MIN(po.expected_delivery_date) AS expected_delivery_date
            FROM purchase_orders po
            LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.station_id = ?
              AND DATE(COALESCE(po.admin_finalized_at, po.created_at)) BETWEEN ? AND ?
            GROUP BY po_number, supplier_name, item_name
        ) po
        LEFT JOIN (
            SELECT
                COALESCE(NULLIF(source_ref, ''), NULLIF(batch_id, ''), delivery_ref) AS po_number,
                product AS item_name,
                SUM(COALESCE(actual_quantity, quantity, 0)) AS received_qty,
                MAX(delivery_date) AS latest_delivery_date,
                GROUP_CONCAT(DISTINCT delivery_ref ORDER BY delivery_ref SEPARATOR ', ') AS delivery_refs,
                GROUP_CONCAT(DISTINCT status ORDER BY status SEPARATOR ', ') AS delivery_status
            FROM deliveries_oversight
            WHERE station_id = ? AND delivery_type = 'merchandise'
            GROUP BY po_number, item_name
        ) d ON d.po_number = po.po_number
             AND LOWER(TRIM(d.item_name)) = LOWER(TRIM(po.item_name))

        UNION ALL

        SELECT
            po.po_type,
            po.po_number,
            po.supplier_name,
            po.item_name,
            po.ordered_qty,
            COALESCE(d.received_qty, 0) AS received_qty,
            po.unit,
            po.expected_delivery_date,
            d.latest_delivery_date,
            COALESCE(d.delivery_refs, '-') AS delivery_refs,
            COALESCE(d.delivery_status, '') AS delivery_status
        FROM (
            SELECT
                'Fuel' AS po_type,
                COALESCE(NULLIF(TRIM(fpo.batch_id), ''), fpo.po_number) AS po_number,
                COALESCE(s.name, 'Petron Corporation') AS supplier_name,
                COALESCE(ft.name, 'Fuel') AS item_name,
                SUM(COALESCE(fpo.volume, 0)) AS ordered_qty,
                'L' AS unit,
                MIN(fpo.expected_delivery_date) AS expected_delivery_date
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
            LEFT JOIN suppliers s ON s.id = fpo.supplier_id
            WHERE fpo.station_id = ?
              AND DATE(fpo.created_at) BETWEEN ? AND ?
            GROUP BY po_number, supplier_name, item_name
        ) po
        LEFT JOIN (
            SELECT
                COALESCE(NULLIF(source_ref, ''), NULLIF(batch_id, ''), delivery_ref) AS po_number,
                product AS item_name,
                SUM(COALESCE(actual_quantity, quantity, 0)) AS received_qty,
                MAX(delivery_date) AS latest_delivery_date,
                GROUP_CONCAT(DISTINCT delivery_ref ORDER BY delivery_ref SEPARATOR ', ') AS delivery_refs,
                GROUP_CONCAT(DISTINCT status ORDER BY status SEPARATOR ', ') AS delivery_status
            FROM deliveries_oversight
            WHERE station_id = ? AND delivery_type = 'fuel'
            GROUP BY po_number, item_name
        ) d ON d.po_number = po.po_number
             AND LOWER(TRIM(d.item_name)) = LOWER(TRIM(po.item_name))
        ORDER BY expected_delivery_date DESC, po_number DESC, item_name ASC
    ");
    $q->execute([
        $station_id, $date_from, $date_to, $station_id,
        $station_id, $date_from, $date_to, $station_id
    ]);
    $comparison_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($comparison_rows as &$row) {
        $row['comparison_status'] = pr_compare_status((float)$row['ordered_qty'], (float)$row['received_qty'], (string)$row['delivery_status']);
        $row['variance_qty'] = (float)$row['received_qty'] - (float)$row['ordered_qty'];
    }
    unset($row);
} catch (Exception $e) {
    $comparison_rows = [];
}

$stockin_rows = [];
try {
    $user_name = pr_user_name_expr('u');
    $q = $pdo->prepare("
        SELECT
            'Merchandise' AS stock_type,
            COALESCE(NULLIF(msi.po_number, ''), NULLIF(do2.source_ref, ''), '-') AS po_number,
            COALESCE(do2.dr_number, '-') AS dr_number,
            COALESCE(msi.batch_ref, '-') AS batch_ref,
            msi.product_name AS item_name,
            msi.qty_ordered AS expected_qty,
            msi.qty_received AS received_qty,
            msi.qty_variance AS variance_qty,
            'pcs' AS unit,
            msi.condition_flag,
            msi.unit_cost,
            msi.selling_price,
            msi.total_cost,
            msi.encoded_at,
            {$user_name} AS approved_by
        FROM merchandise_stock_in msi
        LEFT JOIN deliveries_oversight do2 ON do2.id = msi.delivery_id
        LEFT JOIN users u ON u.id = msi.encoded_by
        WHERE msi.station_id = ?
          AND DATE(msi.encoded_at) BETWEEN ? AND ?

        UNION ALL

        SELECT
            'Fuel' AS stock_type,
            COALESCE(NULLIF(do2.source_ref, ''), NULLIF(fsi.delivery_ref, ''), '-') AS po_number,
            COALESCE(NULLIF(fsi.invoice_no, ''), do2.dr_number, '-') AS dr_number,
            COALESCE(fsi.batch_ref, '-') AS batch_ref,
            fsi.fuel_type AS item_name,
            fsi.qty_expected AS expected_qty,
            fsi.qty_received AS received_qty,
            fsi.qty_variance AS variance_qty,
            'L' AS unit,
            fsi.condition_flag,
            0 AS unit_cost,
            fsi.selling_price_per_liter AS selling_price,
            0 AS total_cost,
            fsi.encoded_at,
            {$user_name} AS approved_by
        FROM fuel_stock_in fsi
        LEFT JOIN deliveries_oversight do2 ON do2.id = fsi.delivery_id
        LEFT JOIN users u ON u.id = fsi.encoded_by
        WHERE fsi.station_id = ?
          AND DATE(fsi.encoded_at) BETWEEN ? AND ?
        ORDER BY encoded_at DESC, batch_ref DESC, item_name ASC
    ");
    $q->execute([$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);
    $stockin_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $stockin_rows = [];
}

$po_numbers = [];
foreach ($po_rows as $row) {
    $po_numbers[$row['po_number']] = true;
}
$summary_po_count = count($po_numbers);
$summary_delivery_count = count(array_unique(array_map(static fn($r) => (string)$r['delivery_ref'], $delivery_rows)));
$summary_pending_count = 0;
$summary_partial_count = 0;
foreach ($comparison_rows as $row) {
    if ($row['comparison_status'] === 'Pending Delivery') $summary_pending_count++;
    if ($row['comparison_status'] === 'Partial Delivery') $summary_partial_count++;
}
$summary_stockin_count = count($stockin_rows);

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.pr-wrapper{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
.pr-filter-bar{display:flex;align-items:center;gap:10px;padding:14px 18px;background:#f8f9fa;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;}
.pr-filter-bar label{font-size:12px;font-weight:600;color:#00264D;margin:0;}
.pr-filter-bar input[type="date"]{padding:7px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;}
.pr-filter-bar button{padding:7px 16px;background:#fff;color:#00264D;border:1px solid #00264D;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;}
.pr-filter-bar button:hover{background:#00264D;color:#fff;}
.pr-export-actions{display:flex;gap:6px;margin-left:auto;}
.pr-export-btn{padding:7px 14px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;border:1px solid;display:inline-flex;align-items:center;gap:6px;background:#fff !important;}
.pr-export-btn:nth-child(1){color:#16a34a !important;border-color:#16a34a !important;}
.pr-export-btn:nth-child(1):hover{background:#f0fdf4 !important;}
.pr-export-btn:nth-child(2){color:#1e40af !important;border-color:#1e40af !important;}
.pr-export-btn:nth-child(2):hover{background:#dbeafe !important;}
.pr-export-btn:nth-child(3){color:#dc2626 !important;border-color:#dc2626 !important;}
.pr-export-btn:nth-child(3):hover{background:#fef2f2 !important;}
.pr-export-btn:nth-child(4){color:#334155 !important;border-color:#64748b !important;}
.pr-export-btn:nth-child(4):hover{background:#f8fafc !important;}
.pr-tabs{display:flex;border-bottom:2px solid #e2e8f0;overflow-x:auto;background:#f8f9fa;}
.pr-tab{padding:13px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#64748b;background:#f8f9fa;border:none;border-bottom:3px solid transparent;cursor:pointer;white-space:nowrap;transition:all .2s;}
.pr-tab:hover{background:#fff;color:#00264D;}
.pr-tab.active{background:#fff;color:#00264D;border-bottom-color:#00264D;font-weight:800;}
.pr-content{padding:24px;}
.pr-summary-grid{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;margin-bottom:20px;}
.pr-card{border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;padding:12px;}
.pr-card-label{font-size:10px;text-transform:uppercase;font-weight:800;color:#64748b;letter-spacing:.4px;margin-bottom:4px;}
.pr-card-value{font-size:22px;font-weight:800;color:#00264D;}
.pr-panel{display:none;}
.pr-panel.active{display:block;}
.pr-rpt-header{text-align:center;padding:20px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:18px;}
.pr-rpt-header .rh-title{font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.pr-rpt-header .rh-sub{font-size:15px;font-weight:700;color:#00264D;text-transform:uppercase;margin-bottom:8px;}
.pr-rpt-header .rh-station{font-size:12px;color:#64748b;margin-bottom:2px;}
.pr-rpt-header .rh-date{font-size:12px;color:#334155;}
.pr-table-wrap{width:100%;overflow-x:auto;}
.pr-tbl{width:100%;min-width:980px;border-collapse:collapse;font-size:12px;}
.pr-tbl thead tr{border-top:2px solid #00264D;border-bottom:1px solid #e2e8f0;background:#f8f9fa;}
.pr-tbl thead th{padding:10px 8px;text-align:left;font-weight:700;color:#00264D;font-size:11px;text-transform:uppercase;}
.pr-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.pr-tbl tbody tr:hover{background:#f8fafc;}
.pr-tbl tbody td{padding:9px 8px;color:#334155;font-size:12px;vertical-align:top;}
.pr-tbl tfoot tr{border-top:2px solid #00264D;background:#f0f4ff;}
.pr-tbl tfoot td{padding:10px 8px;font-weight:700;color:#00264D;font-size:12px;}
.pr-empty{text-align:center;padding:28px;color:#94a3b8;font-size:13px;}
.pr-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800;white-space:nowrap;}
.pr-badge.ok{background:#dcfce7;color:#15803d;}
.pr-badge.warn{background:#fef9c3;color:#854d0e;}
.pr-badge.bad{background:#fee2e2;color:#dc2626;}
.pr-badge.info{background:#dbeafe;color:#1d4ed8;}
.pr-mono{font-family:Consolas,Monaco,monospace;font-weight:700;color:#00264D;}
.pr-neg{color:#dc2626;font-weight:700;}
.pr-pos{color:#15803d;font-weight:700;}
@media(max-width:900px){
    .pr-summary-grid{grid-template-columns:repeat(2,minmax(130px,1fr));}
    .pr-export-actions{margin-left:0;width:100%;flex-wrap:wrap;}
}
@media print{
    @page{
        size:legal landscape;
        margin:.3in .4in;
        /* Suppress browser-injected URL/date headers and footers */
        @top-left{content:none;}
        @top-center{content:none;}
        @top-right{content:none;}
        @bottom-left{content:none;}
        @bottom-center{content:none;}
        @bottom-right{content:none;}
    }
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}

    /* Hide everything except the printable area */
    html,body{background:#fff !important;padding:0 !important;margin:0 !important;width:100% !important;overflow:visible !important;}
    .sidebar,aside,.top-header,header,nav,.sidebar-identity-footer,
    .pr-filter-bar,.pr-tabs,.pr-export-actions,.pr-summary-grid{display:none !important;}

    /* Reset main layout so there is no sidebar offset */
    .main,main{
        margin-left:0 !important;
        padding:0 !important;
        width:100% !important;
        max-width:100% !important;
        float:none !important;
        position:static !important;
        display:block !important;
    }

    /* Wrapper fills full page */
    .pr-wrapper{
        box-shadow:none !important;
        border-radius:0 !important;
        width:100% !important;
        overflow:visible !important;
    }
    .pr-content{padding:0 !important;width:100% !important;}

    /* Printable section */
    .pr-printable{display:block !important;overflow:visible !important;width:100% !important;}

    /* Hide all panels except active */
    .pr-panel{display:none !important;}
    .pr-panel.active{display:block !important;width:100% !important;}

    /* Report header centered */
    .pr-rpt-header{
        break-after:avoid !important;page-break-after:avoid !important;
        text-align:center !important;width:100% !important;
    }
    .rh-title,.rh-sub,.rh-station,.rh-date{text-align:center !important;display:block !important;}

    /* Table fills full width */
    .pr-table-wrap{overflow:visible !important;width:100% !important;}
    .pr-tbl{
        width:100% !important;min-width:0 !important;
        table-layout:auto !important;font-size:8.8px !important;
        break-inside:auto !important;page-break-inside:auto !important;
        margin:0 !important;
    }
    .pr-tbl thead{display:table-header-group !important;}
    .pr-tbl tfoot{display:table-footer-group !important;}
    .pr-tbl tr{break-inside:avoid !important;page-break-inside:avoid !important;}
    .pr-tbl th,.pr-tbl td{font-size:8.6px !important;padding:4px !important;white-space:normal !important;word-break:break-word !important;}
}
</style>

<div class="pr-wrapper">
    <form method="GET" class="pr-filter-bar">
        <input type="hidden" name="section" value="<?= pr_h($section) ?>">
        <label><i class="fas fa-calendar"></i> Report Date:</label>
        <input type="date" name="date_from" value="<?= pr_h($date_from) ?>" required>
        <span style="color:#64748b;">to</span>
        <input type="date" name="date_to" value="<?= pr_h($date_to) ?>" required>
        <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
        <div class="pr-export-actions">
            <button type="button" class="pr-export-btn" onclick="prExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="button" class="pr-export-btn" onclick="prExport('csv')"><i class="fas fa-file-csv"></i> CSV</button>
            <button type="button" class="pr-export-btn" onclick="prExport('pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
            <button type="button" class="pr-export-btn" onclick="prPrint()"><i class="fas fa-print"></i> Print</button>
        </div>
    </form>

    <div class="pr-tabs">
        <button type="button" class="pr-tab <?= $section === 'po' ? 'active' : '' ?>" onclick="prTab('po')"><i class="fas fa-file-invoice-dollar"></i> Purchase Orders</button>
        <button type="button" class="pr-tab <?= $section === 'delivery' ? 'active' : '' ?>" onclick="prTab('delivery')"><i class="fas fa-truck-loading"></i> Delivery Receipts</button>
        <button type="button" class="pr-tab <?= $section === 'comparison' ? 'active' : '' ?>" onclick="prTab('comparison')"><i class="fas fa-balance-scale"></i> PO vs Delivery</button>
        <button type="button" class="pr-tab <?= $section === 'stockin' ? 'active' : '' ?>" onclick="prTab('stockin')"><i class="fas fa-clipboard-check"></i> Stock-In Approvals</button>
    </div>

    <div class="pr-content">
        <div class="pr-summary-grid">
            <div class="pr-card"><div class="pr-card-label">Purchase Orders</div><div class="pr-card-value"><?= number_format($summary_po_count) ?></div></div>
            <div class="pr-card"><div class="pr-card-label">Delivery Receipts</div><div class="pr-card-value"><?= number_format($summary_delivery_count) ?></div></div>
            <div class="pr-card"><div class="pr-card-label">Pending Items</div><div class="pr-card-value"><?= number_format($summary_pending_count) ?></div></div>
            <div class="pr-card"><div class="pr-card-label">Partial Items</div><div class="pr-card-value"><?= number_format($summary_partial_count) ?></div></div>
            <div class="pr-card"><div class="pr-card-label">Stock-In Rows</div><div class="pr-card-value"><?= number_format($summary_stockin_count) ?></div></div>
        </div>

        <div class="pr-printable">
            <div class="pr-panel <?= $section === 'po' ? 'active' : '' ?>">
                <div class="pr-rpt-header">
                    <div class="rh-title">Purchase Order Report</div>
                    <div class="rh-sub">PO generation and supplier ordering</div>
                    <div class="rh-station"><?= pr_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= pr_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . pr_date($date_to) : '' ?></div>
                </div>
                <div class="pr-table-wrap">
                    <table class="pr-tbl">
                        <thead><tr>
                            <th>PO No.</th><th>Type</th><th>Supplier</th><th>Item</th><th>Qty Ordered</th>
                            <th>Unit Price</th><th>Total Amount</th><th>Expected Delivery</th><th>Status</th><th>Generated By</th><th>PO Date</th>
                        </tr></thead>
                        <tbody>
                        <?php $po_total = 0; if (empty($po_rows)): ?>
                            <tr><td colspan="11" class="pr-empty">No purchase orders for this period.</td></tr>
                        <?php else: foreach ($po_rows as $r): $po_total += (float)$r['total_amount']; ?>
                            <tr>
                                <td class="pr-mono"><?= pr_h($r['po_number']) ?></td>
                                <td><?= pr_h($r['po_type']) ?></td>
                                <td><?= pr_h($r['supplier_name']) ?></td>
                                <td><?= pr_h($r['item_name']) ?></td>
                                <td><?= pr_qty($r['ordered_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_money($r['unit_price']) ?></td>
                                <td><?= pr_money($r['total_amount']) ?></td>
                                <td><?= pr_date($r['expected_delivery_date']) ?></td>
                                <td><span class="pr-badge <?= pr_status_class($r['status']) ?>"><?= pr_h($r['status']) ?></span></td>
                                <td><?= pr_h($r['generated_by']) ?></td>
                                <td><?= pr_datetime($r['po_date']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($po_rows)): ?>
                        <tfoot><tr><td colspan="6">TOTAL</td><td><?= pr_money($po_total) ?></td><td colspan="4"></td></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="pr-panel <?= $section === 'delivery' ? 'active' : '' ?>">
                <div class="pr-rpt-header">
                    <div class="rh-title">Delivery Receipt Report</div>
                    <div class="rh-sub">Supplier delivery records and DR details</div>
                    <div class="rh-station"><?= pr_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= pr_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . pr_date($date_to) : '' ?></div>
                </div>
                <div class="pr-table-wrap">
                    <table class="pr-tbl">
                        <thead><tr>
                            <th>Delivery Ref</th><th>PO No.</th><th>DR No.</th><th>Invoice No.</th><th>Type</th>
                            <th>Supplier</th><th>Item</th><th>Expected</th><th>Received</th><th>Damaged</th>
                            <th>Date</th><th>Status</th><th>Received By</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($delivery_rows)): ?>
                            <tr><td colspan="13" class="pr-empty">No delivery receipts for this period.</td></tr>
                        <?php else: foreach ($delivery_rows as $r): ?>
                            <tr>
                                <td class="pr-mono"><?= pr_h($r['delivery_ref']) ?></td>
                                <td class="pr-mono"><?= pr_h($r['po_number']) ?></td>
                                <td><?= pr_h($r['dr_number']) ?></td>
                                <td><?= pr_h($r['sales_invoice_no']) ?></td>
                                <td><?= pr_h($r['delivery_type']) ?></td>
                                <td><?= pr_h($r['supplier_name']) ?></td>
                                <td><?= pr_h($r['item_name']) ?></td>
                                <td><?= pr_qty($r['expected_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_qty($r['received_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_qty($r['damaged_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_date($r['delivery_date']) ?></td>
                                <td><span class="pr-badge <?= pr_status_class($r['status']) ?>"><?= pr_h($r['status']) ?></span></td>
                                <td><?= pr_h($r['received_by']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($delivery_rows)): ?>
                        <tfoot><tr><td colspan="13">TOTAL DELIVERY RECEIPTS: <?= number_format($summary_delivery_count) ?></td></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="pr-panel <?= $section === 'comparison' ? 'active' : '' ?>">
                <div class="pr-rpt-header">
                    <div class="rh-title">PO vs Delivery Report</div>
                    <div class="rh-sub">Ordered quantity against received quantity</div>
                    <div class="rh-station"><?= pr_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= pr_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . pr_date($date_to) : '' ?></div>
                </div>
                <div class="pr-table-wrap">
                    <table class="pr-tbl">
                        <thead><tr>
                            <th>PO No.</th><th>Type</th><th>Supplier</th><th>Item</th><th>Ordered</th>
                            <th>Received</th><th>Variance</th><th>Expected Date</th><th>Latest Delivery</th><th>Delivery Ref</th><th>Status</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($comparison_rows)): ?>
                            <tr><td colspan="11" class="pr-empty">No PO comparison records for this period.</td></tr>
                        <?php else: foreach ($comparison_rows as $r):
                            $variance = (float)$r['variance_qty'];
                            $variance_class = $variance < 0 ? 'pr-neg' : ($variance > 0 ? 'pr-pos' : '');
                        ?>
                            <tr>
                                <td class="pr-mono"><?= pr_h($r['po_number']) ?></td>
                                <td><?= pr_h($r['po_type']) ?></td>
                                <td><?= pr_h($r['supplier_name']) ?></td>
                                <td><?= pr_h($r['item_name']) ?></td>
                                <td><?= pr_qty($r['ordered_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_qty($r['received_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td class="<?= $variance_class ?>"><?= ($variance > 0 ? '+' : '') . pr_qty($variance) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_date($r['expected_delivery_date']) ?></td>
                                <td><?= pr_date($r['latest_delivery_date']) ?></td>
                                <td><?= pr_h($r['delivery_refs']) ?></td>
                                <td><span class="pr-badge <?= pr_status_class($r['comparison_status']) ?>"><?= pr_h($r['comparison_status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($comparison_rows)): ?>
                        <tfoot><tr><td colspan="11">PENDING ITEMS: <?= number_format($summary_pending_count) ?> | PARTIAL ITEMS: <?= number_format($summary_partial_count) ?></td></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="pr-panel <?= $section === 'stockin' ? 'active' : '' ?>">
                <div class="pr-rpt-header">
                    <div class="rh-title">Stock-In Approval Report</div>
                    <div class="rh-sub">Manager-approved deliveries added to inventory</div>
                    <div class="rh-station"><?= pr_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= pr_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . pr_date($date_to) : '' ?></div>
                </div>
                <div class="pr-table-wrap">
                    <table class="pr-tbl">
                        <thead><tr>
                            <th>Batch Ref</th><th>PO No.</th><th>DR / Invoice</th><th>Type</th><th>Item</th>
                            <th>Expected</th><th>Received</th><th>Variance</th><th>Condition</th><th>Unit Cost</th>
                            <th>Selling Price</th><th>Approved By</th><th>Approved At</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($stockin_rows)): ?>
                            <tr><td colspan="13" class="pr-empty">No stock-in approval records for this period.</td></tr>
                        <?php else: foreach ($stockin_rows as $r):
                            $variance = (float)$r['variance_qty'];
                            $variance_class = $variance < 0 ? 'pr-neg' : ($variance > 0 ? 'pr-pos' : '');
                        ?>
                            <tr>
                                <td class="pr-mono"><?= pr_h($r['batch_ref']) ?></td>
                                <td class="pr-mono"><?= pr_h($r['po_number']) ?></td>
                                <td><?= pr_h($r['dr_number']) ?></td>
                                <td><?= pr_h($r['stock_type']) ?></td>
                                <td><?= pr_h($r['item_name']) ?></td>
                                <td><?= pr_qty($r['expected_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td><?= pr_qty($r['received_qty']) ?> <?= pr_h($r['unit']) ?></td>
                                <td class="<?= $variance_class ?>"><?= ($variance > 0 ? '+' : '') . pr_qty($variance) ?> <?= pr_h($r['unit']) ?></td>
                                <td><span class="pr-badge <?= pr_status_class($r['condition_flag']) ?>"><?= pr_h($r['condition_flag']) ?></span></td>
                                <td><?= pr_money($r['unit_cost']) ?></td>
                                <td><?= pr_money($r['selling_price']) ?></td>
                                <td><?= pr_h($r['approved_by']) ?></td>
                                <td><?= pr_datetime($r['encoded_at']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($stockin_rows)): ?>
                        <tfoot><tr><td colspan="13">TOTAL STOCK-IN ROWS: <?= number_format($summary_stockin_count) ?></td></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function prTab(key) {
    const url = new URL(window.location.href);
    url.searchParams.set('section', key);
    const df = document.querySelector('input[name="date_from"]');
    const dt = document.querySelector('input[name="date_to"]');
    if (df) url.searchParams.set('date_from', df.value);
    if (dt) url.searchParams.set('date_to', dt.value);
    window.location.href = url.toString();
}

function prTableToAoA(table) {
    const aoa = [];
    table.querySelectorAll('thead tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim()));
    });
    table.querySelectorAll('tbody tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    table.querySelectorAll('tfoot tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    return aoa;
}

function prAutoWidth(ws, aoa) {
    if (!aoa.length || !aoa[0]) return;
    ws['!cols'] = aoa[0].map((_, ci) => ({
        wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] || '').length)))
    }));
}

function prExport(type) {
    const active = document.querySelector('.pr-panel.active');
    if (!active) {
        alert('No active report section found.');
        return;
    }
    const tables = active.querySelectorAll('table.pr-tbl');
    if (!tables.length) {
        alert('No table data found.');
        return;
    }
    const section = new URL(window.location).searchParams.get('section') || 'po';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Procurement_Report_${section}_${dateFrom}_${dateTo}`;

    if (type === 'pdf') {
        exportPrintableAreaToPDF(active, 'Admin Procurement Report', filename, document.activeElement);
        return;
    }

    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }

    if (type === 'csv') {
        let csv = '';
        tables.forEach((table, index) => {
            if (index > 0) csv += '\n';
            prTableToAoA(table).forEach(row => {
                csv += row.map(cell => '"' + String(cell).replace(/"/g, '""') + '"').join(',') + '\n';
            });
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
        return;
    }

    const wb = XLSX.utils.book_new();
    tables.forEach((table, index) => {
        const aoa = prTableToAoA(table);
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        prAutoWidth(ws, aoa);
        const title = active.querySelector('.rh-title')?.innerText?.trim() || `Sheet ${index + 1}`;
        XLSX.utils.book_append_sheet(wb, ws, title.replace(/[:\\\/?*\[\]]/g, '').substring(0, 31) || 'Report');
    });
    XLSX.writeFile(wb, filename + '.xlsx');
}

function prPrint() {
    window.print();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
