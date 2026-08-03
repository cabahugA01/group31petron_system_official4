<?php
/**
 * Manager Procurement Reports
 * Delivery Validation | PO vs Received | Stock-In Approval | Delivery Variance
 */

$page_id = 'manager_procurement_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
    die('Access denied. Manager privileges required.');
}

if (!in_array($role, ['superadmin', 'developer'], true) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$valid_sections = ['purchase', 'validation', 'comparison', 'stockin', 'variance', 'fuel_variance'];
$section = in_array($_GET['section'] ?? '', $valid_sections, true) ? $_GET['section'] : 'validation';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

$fuel_type_filter   = $_GET['fuel_type'] ?? '';
$ugt_filter         = $_GET['ugt_no'] ?? '';
$variance_filter    = $_GET['variance_status'] ?? '';
$status_filter      = $_GET['adj_status'] ?? '';
$po_type_filter     = $_GET['po_type'] ?? '';
$po_status_filter   = $_GET['po_status'] ?? '';
$po_supplier_filter = $_GET['po_supplier'] ?? '';

$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

function mp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mp_money($value): string
{
    return '&#8369;' . number_format((float)$value, 2);
}

function mp_qty($value): string
{
    $text = number_format((float)$value, 2);
    return rtrim(rtrim($text, '0'), '.');
}

function mp_date($value): string
{
    $ts = strtotime((string)$value);
    return $ts ? date('m/d/Y', $ts) : '-';
}

function mp_datetime($value): string
{
    $ts = strtotime((string)$value);
    return $ts ? date('m/d/Y H:i', $ts) : '-';
}

function mp_user_expr(string $alias): string
{
    return "COALESCE(NULLIF(TRIM(CONCAT(COALESCE({$alias}.first_name,''),' ',COALESCE({$alias}.last_name,''))),''), {$alias}.name, {$alias}.username, '-')";
}

function mp_status_class($status): string
{
    $s = strtolower((string)$status);
    if (strpos($s, 'complete') !== false || strpos($s, 'approved') !== false || strpos($s, 'validated') !== false || strpos($s, 'verified') !== false || strpos($s, 'good') !== false || strpos($s, 'ready') !== false) {
        return 'ok';
    }
    if (strpos($s, 'pending') !== false || strpos($s, 'partial') !== false || strpos($s, 'expected') !== false || strpos($s, 'short') !== false || strpos($s, 'adjusted') !== false) {
        return 'warn';
    }
    if (strpos($s, 'reject') !== false || strpos($s, 'cancel') !== false || strpos($s, 'damaged') !== false || strpos($s, 'discrep') !== false) {
        return 'bad';
    }
    return 'info';
}

function mp_compare_status(float $ordered, float $received, string $raw_status = ''): string
{
    if (stripos($raw_status, 'damaged') !== false) return 'Damaged Items';
    if ($received <= 0) return 'Pending Delivery';
    if ($received < $ordered) return 'Partial Delivery';
    if ($received > $ordered) return 'Excess Delivery';
    return 'Complete Delivery';
}

$delivery_rows = [];
try {
    $encoded_by = mp_user_expr('u');
    $manager_by = mp_user_expr('m');
    $q = $pdo->prepare("
        SELECT
            do2.id,
            CASE WHEN do2.delivery_type = 'fuel' THEN 'Fuel' ELSE 'Merchandise' END AS delivery_type,
            COALESCE(NULLIF(do2.source_ref, ''), NULLIF(do2.batch_id, ''), '-') AS po_number,
            do2.delivery_ref,
            COALESCE(do2.dr_number, '-') AS dr_number,
            COALESCE(do2.sales_invoice_no, '-') AS invoice_no,
            COALESCE(do2.supplier, '-') AS supplier_name,
            COALESCE(do2.product, '-') AS item_name,
            COALESCE(do2.expected_quantity, do2.quantity, 0) AS expected_qty,
            COALESCE(do2.actual_quantity, do2.quantity, 0) AS received_qty,
            COALESCE(do2.damaged_quantity, 0) AS damaged_qty,
            COALESCE(do2.unit, '') AS unit,
            COALESCE(do2.unit_price, do2.unit_cost, 0) AS unit_price,
            do2.delivery_date,
            do2.status,
            COALESCE(NULLIF(do2.received_by_name, ''), {$encoded_by}) AS recorded_by,
            COALESCE({$manager_by}, '-') AS manager_name,
            do2.manager_action_at,
            COALESCE(do2.manager_notes, do2.remarks, '') AS notes
        FROM deliveries_oversight do2
        LEFT JOIN users u ON u.id = do2.encoded_by
        LEFT JOIN users m ON m.id = do2.manager_id
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
        $row['variance_qty'] = (float)$row['received_qty'] - (float)$row['ordered_qty'];
        $row['comparison_status'] = mp_compare_status((float)$row['ordered_qty'], (float)$row['received_qty'], (string)$row['delivery_status']);
    }
    unset($row);
} catch (Exception $e) {
    $comparison_rows = [];
}

$stockin_rows = [];
try {
    $approved_by = mp_user_expr('u');
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
            {$approved_by} AS approved_by
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
            {$approved_by} AS approved_by
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

$variance_rows = [];
foreach ($comparison_rows as $r) {
    if (($r['comparison_status'] ?? '') !== 'Complete Delivery') {
        $variance_rows[] = [
            'po_number' => $r['po_number'],
            'delivery_ref' => $r['delivery_refs'],
            'type' => $r['po_type'],
            'supplier_name' => $r['supplier_name'],
            'item_name' => $r['item_name'],
            'expected_qty' => $r['ordered_qty'],
            'received_qty' => $r['received_qty'],
            'variance_qty' => $r['variance_qty'],
            'unit' => $r['unit'],
            'status' => $r['comparison_status'],
            'notes' => $r['delivery_status'] ?: '-',
        ];
    }
}
foreach ($delivery_rows as $r) {
    $status = strtolower((string)$r['status']);
    $is_issue = (float)$r['damaged_qty'] > 0
        || str_contains($status, 'damaged')
        || str_contains($status, 'partial')
        || str_contains($status, 'short')
        || str_contains($status, 'excess')
        || str_contains($status, 'discrep');
    if ($is_issue) {
        $variance_rows[] = [
            'po_number' => $r['po_number'],
            'delivery_ref' => $r['delivery_ref'],
            'type' => $r['delivery_type'],
            'supplier_name' => $r['supplier_name'],
            'item_name' => $r['item_name'],
            'expected_qty' => $r['expected_qty'],
            'received_qty' => $r['received_qty'],
            'variance_qty' => (float)$r['received_qty'] - (float)$r['expected_qty'],
            'unit' => $r['unit'],
            'status' => $r['status'],
            'notes' => $r['notes'] ?: '-',
        ];
    }
}

$summary_deliveries = count(array_unique(array_map(static fn($r) => (string)$r['delivery_ref'], $delivery_rows)));
$summary_pending = 0;
$summary_validated = 0;
foreach ($delivery_rows as $r) {
    $status = strtolower((string)$r['status']);
    if (str_contains($status, 'pending') || str_contains($status, 'expected')) $summary_pending++;
    if (str_contains($status, 'validated') || str_contains($status, 'verified') || str_contains($status, 'ready') || str_contains($status, 'complete')) $summary_validated++;
}
$summary_variance = count($variance_rows);
// â”€â”€ Fetch Fuel Variance Rows & Summary â”€â”€
// â”€â”€ Fuel Variance Report — Real Data from fuel_adjustments + fuel_inventory â”€â”€
$fuel_variance_rows = [];

try {
    /*
     * fuel_adjustments columns used:
     *   id               â†’ Reference No.
     *   adjustment_date  â†’ Date
     *   ugt_no           â†’ UGT No. (directly stored on adjustment)
     *   fuel_type        â†’ Fuel Type
     *   previous_value   â†’ System Current Volume (before adjustment)
     *   new_value        â†’ Actual Tank Dip Volume (after physical count)
     *   variance         â†’ stored variance (liters)
     *   adjustment_direction â†’ Increase / Decrease (direction of adjustment)
     *   adjustment_type  â†’ Reason (Physical Count, Encoding Error, etc.)
     *   reason           â†’ Adjustment Reason (free text from staff)
     *   notes            â†’ Remarks
     *   status           â†’ Adjustment Status (Pending / Approved / Rejected)
     *   approved_by      â†’ for resolved_by display
     *   liters           â†’ Adjustment amount in liters
     *
     * fuel_inventory joined to get current_stock snapshot for context.
     */
    $fv_stmt = $pdo->prepare("
        SELECT
            fa.id                                               AS ref_no,
            fa.adjustment_date                                  AS report_date,
            COALESCE(fa.ugt_no, fi.ugt_no, '-')                AS ugt_no,
            fa.fuel_type                                        AS fuel_type,
            COALESCE(fi.current_stock, fa.previous_value)       AS system_volume,
            fa.previous_value                                   AS system_before,
            fa.new_value                                        AS actual_dip,
            (fa.new_value - fa.previous_value)                  AS variance_liters,
            fa.liters                                           AS adjustment_liters,
            fa.adjustment_direction                             AS adj_direction,
            COALESCE(fa.adjustment_type, '-')                   AS adj_reason,
            COALESCE(fa.reason, '-')                            AS adj_detail,
            COALESCE(fa.notes, '')                              AS remarks,
            fa.status                                           AS adj_status,
            COALESCE(u.name, '-')                               AS approved_by_name
        FROM fuel_adjustments fa
        LEFT JOIN fuel_inventory fi
            ON fi.fuel_type_id = fa.fuel_type_id
           AND fi.station_id   = fa.station_id
        LEFT JOIN users u
            ON u.id = fa.approved_by
        WHERE fa.station_id = ?
          AND DATE(fa.adjustment_date) BETWEEN ? AND ?
        ORDER BY fa.adjustment_date DESC, fa.id DESC
    ");
    $fv_stmt->execute([$station_id, $date_from, $date_to]);
    $fuel_variance_rows = $fv_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Exception $e) {
    $fuel_variance_rows = [];
}

// â”€â”€ Summary Metrics (computed from real rows) â”€â”€
$fv_total_variance = 0.0;
$fv_pos_variance   = 0.0;
$fv_neg_variance   = 0.0;
$fv_pending_count  = 0;
$fv_approved_count = 0;

foreach ($fuel_variance_rows as $row) {
    $v    = (float)($row['variance_liters'] ?? 0);
    $sLow = strtolower((string)($row['adj_status'] ?? ''));
    $fv_total_variance += $v;
    if ($v > 0) $fv_pos_variance += $v;
    if ($v < 0) $fv_neg_variance += $v;
    if (strpos($sLow, 'pending') !== false)                                                                $fv_pending_count++;
    elseif (strpos($sLow, 'approved') !== false || strpos($sLow, 'adjusted') !== false
         || strpos($sLow, 'verified') !== false || strpos($sLow, 'resolved') !== false) $fv_approved_count++;
}

// ── Purchase Report Data ──────────────────────────────────────────────────
$purchase_rows = [];
try {
    $req_expr  = mp_user_expr('u_req');
    $appr_expr = mp_user_expr('u_appr');

    // Build WHERE clauses for filters
    $po_where_merch = 'po.station_id = ? AND DATE(COALESCE(po.created_at, po.submitted_at)) BETWEEN ? AND ?';
    $po_where_fuel  = 'fpo.station_id = ? AND DATE(fpo.created_at) BETWEEN ? AND ?';
    $merch_params   = [$station_id, $date_from, $date_to];
    $fuel_params    = [$station_id, $date_from, $date_to];

    if ($po_status_filter !== '') {
        $po_where_merch .= ' AND po.status LIKE ?';
        $merch_params[] = '%' . $po_status_filter . '%';
        $po_where_fuel  .= ' AND fpo.status LIKE ?';
        $fuel_params[]  = '%' . $po_status_filter . '%';
    }

    $merch_union = ($po_type_filter === '' || $po_type_filter === 'Merchandise');
    $fuel_union  = ($po_type_filter === '' || $po_type_filter === 'Fuel');

    $union_parts = [];
    $union_params = [];

    if ($merch_union) {
        $union_parts[] = "
            SELECT
                'Merchandise' AS po_type,
                COALESCE(NULLIF(TRIM(po.batch_id),''), po.po_number, CONCAT('PO-',po.id)) AS po_number,
                DATE(COALESCE(po.created_at, po.submitted_at)) AS po_date,
                COALESCE(s.name, 'Petron Corporation') AS supplier_name,
                COUNT(DISTINCT COALESCE(poi.id, 0)) AS total_items,
                COALESCE(SUM(COALESCE(poi.quantity_ordered, poi.quantity, 0)), po.quantity, 0) AS total_qty,
                COALESCE(SUM(COALESCE(poi.total_price, 0)), po.total_amount, 0) AS total_amount,
                $req_expr AS requested_by,
                $appr_expr AS approved_by,
                po.status,
                po.id AS po_id,
                po.remarks
            FROM purchase_orders po
            LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN users u_req ON u_req.id = po.created_by
            LEFT JOIN users u_appr ON u_appr.id = po.approved_by
            WHERE $po_where_merch
            GROUP BY po.id
        ";
        $union_params = array_merge($union_params, $merch_params);
    }

    if ($fuel_union) {
        $union_parts[] = "
            SELECT
                'Fuel' AS po_type,
                COALESCE(NULLIF(TRIM(fpo.batch_id),''), fpo.po_number, CONCAT('FPO-',fpo.id)) AS po_number,
                DATE(fpo.created_at) AS po_date,
                COALESCE(s.name, 'Petron Corporation') AS supplier_name,
                1 AS total_items,
                COALESCE(fpo.volume, 0) AS total_qty,
                COALESCE(fpo.total_amount, 0) AS total_amount,
                $req_expr AS requested_by,
                $appr_expr AS approved_by,
                fpo.status,
                fpo.id AS po_id,
                COALESCE(fpo.notes, '') AS remarks
            FROM fuel_purchase_orders fpo
            LEFT JOIN suppliers s ON s.id = fpo.supplier_id
            LEFT JOIN users u_req ON u_req.id = fpo.created_by
            LEFT JOIN users u_appr ON u_appr.id = fpo.approved_by
            WHERE $po_where_fuel
        ";
        $union_params = array_merge($union_params, $fuel_params);
    }

    if (!empty($union_parts)) {
        $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $union_parts) . ') AS combined';
        if ($po_supplier_filter !== '') {
            $sql .= ' WHERE supplier_name LIKE ?';
            $union_params[] = '%' . $po_supplier_filter . '%';
        }
        $sql .= ' ORDER BY po_date DESC, po_number DESC';
        $q = $pdo->prepare($sql);
        $q->execute($union_params);
        $purchase_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {
    $purchase_rows = [];
}

// Build purchase items JSON per PO for drill-down modal
$purchase_items_json = [];
try {
    // Merchandise items
    $qi = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(po.batch_id),''), po.po_number, CONCAT('PO-',po.id)) AS po_number,
            'Merchandise' AS po_type,
            COALESCE(poi.item_name, po.product_name, '-') AS item_name,
            COALESCE(poi.quantity_ordered, poi.quantity, 0) AS qty_ordered,
            COALESCE(poi.unit_price, 0) AS unit_cost,
            COALESCE(poi.total_price, poi.quantity * poi.unit_price, 0) AS total
        FROM purchase_orders po
        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
        WHERE po.station_id = ? AND DATE(COALESCE(po.created_at, po.submitted_at)) BETWEEN ? AND ?
        ORDER BY po.id, poi.id
    ");
    $qi->execute([$station_id, $date_from, $date_to]);
    foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $purchase_items_json[$it['po_number']][] = $it;
    }
    // Fuel items
    $qf = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(fpo.batch_id),''), fpo.po_number, CONCAT('FPO-',fpo.id)) AS po_number,
            'Fuel' AS po_type,
            COALESCE(ft.name, 'Fuel') AS item_name,
            COALESCE(fpo.volume, 0) AS qty_ordered,
            COALESCE(fpo.unit_price, 0) AS unit_cost,
            COALESCE(fpo.total_amount, 0) AS total
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
        WHERE fpo.station_id = ? AND DATE(fpo.created_at) BETWEEN ? AND ?
        ORDER BY fpo.id
    ");
    $qf->execute([$station_id, $date_from, $date_to]);
    foreach ($qf->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $purchase_items_json[$it['po_number']][] = $it;
    }
} catch (Exception $e) {}

// Supplier list for filter dropdown
$po_suppliers = [];
try {
    $qs = $pdo->query("SELECT DISTINCT COALESCE(s.name,'Petron Corporation') AS sname FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id WHERE po.station_id=$station_id AND s.name IS NOT NULL UNION SELECT DISTINCT COALESCE(s.name,'Petron Corporation') FROM fuel_purchase_orders fpo LEFT JOIN suppliers s ON s.id=fpo.supplier_id WHERE fpo.station_id=$station_id AND s.name IS NOT NULL ORDER BY sname");
    $po_suppliers = $qs->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

$purchase_total_amount = array_sum(array_column($purchase_rows, 'total_amount'));
$purchase_total_qty    = array_sum(array_column($purchase_rows, 'total_qty'));

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.mp-wrapper{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
.mp-filter-bar{display:flex;align-items:center;gap:10px;padding:14px 18px;background:#f8f9fa;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;}
.mp-filter-bar label{font-size:12px;font-weight:600;color:#00264D;margin:0;}
.mp-filter-bar button{padding:7px 16px;background:#00264D !important;color:#ffffff !important;border:1px solid #00264D;border-radius:4px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
.mp-filter-bar button i{color:#ffffff !important;}
.mp-filter-bar button:hover{background:#001933 !important;color:#ffffff !important;}
.mp-export-actions{display:flex;gap:6px;margin-left:auto;}
.mp-export-btn{padding:7px 14px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;border:1px solid;display:inline-flex;align-items:center;gap:6px;background:#ffffff !important;}
.mp-export-btn:nth-child(1){color:#16a34a !important;border-color:#16a34a !important;background:#ffffff !important;}
.mp-export-btn:nth-child(1):hover{background:#f0fdf4 !important;border-color:#15803d !important;color:#15803d !important;}
.mp-export-btn:nth-child(2){color:#16a34a !important;border-color:#16a34a !important;background:#ffffff !important;}
.mp-export-btn:nth-child(2):hover{background:#f0fdf4 !important;border-color:#15803d !important;color:#15803d !important;}
.mp-export-btn:nth-child(3){color:#dc2626 !important;border-color:#dc2626 !important;background:#ffffff !important;}
.mp-export-btn:nth-child(3):hover{background:#fef2f2 !important;border-color:#b91c1c !important;color:#b91c1c !important;}
.mp-export-btn:nth-child(4){color:#475569 !important;border-color:#cbd5e1 !important;background:#ffffff !important;}
.mp-export-btn:nth-child(4):hover{background:#f8fafc !important;border-color:#64748b !important;color:#1e293b !important;}
.mp-tabs{display:flex;flex-wrap:wrap;background:#ffffff;border-top:1px solid #cbd5e1;border-bottom:2px solid #00264D;padding:0;margin:0;}
.mp-tab{padding:12px 24px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#334155 !important;background:#ffffff !important;border:none !important;border-right:1px solid #e2e8f0 !important;border-radius:0 !important;cursor:pointer;white-space:nowrap;transition:all .15s ease;display:inline-flex;align-items:center;gap:8px;}
.mp-tab i{font-size:11px;color:#64748b !important;}
.mp-tab:hover{background:#f8fafc !important;color:#00264D !important;}
.mp-tab:hover i{color:#00264D !important;}
.mp-tab.active{background:#00264D !important;color:#ffffff !important;font-weight:800;border-right-color:#00264D !important;}
.mp-tab.active i{color:#ffffff !important;}
.mp-content{padding:24px;}
.mp-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px;margin-bottom:20px;}
.mp-card{border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;padding:12px;}
.mp-card-label{font-size:10px;text-transform:uppercase;font-weight:800;color:#64748b;letter-spacing:.4px;margin-bottom:4px;}
.mp-card-value{font-size:22px;font-weight:800;color:#00264D;}
.mp-panel{display:none;}
.mp-panel.active{display:block;}
.mp-rpt-header{text-align:center;padding:20px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:18px;}
.mp-rpt-header .rh-title{font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.mp-rpt-header .rh-sub{font-size:15px;font-weight:700;color:#00264D;text-transform:uppercase;margin-bottom:8px;}
.mp-rpt-header .rh-station{font-size:12px;color:#64748b;margin-bottom:2px;}
.mp-rpt-header .rh-date{font-size:12px;color:#334155;}
.mp-table-wrap{width:100%;overflow-x:auto;}
.mp-tbl{width:100%;min-width:980px;border-collapse:collapse;font-size:12px;}
.mp-tbl thead tr{border-top:2px solid #00264D;border-bottom:1px solid #e2e8f0;background:#f8f9fa;}
.mp-tbl thead th{padding:10px 8px;text-align:left;font-weight:700;color:#00264D;font-size:11px;text-transform:uppercase;}
.mp-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.mp-tbl tbody tr:hover{background:#f8fafc;}
.mp-tbl tbody td{padding:9px 8px;color:#334155;font-size:12px;vertical-align:top;}
.mp-tbl tfoot tr{border-top:2px solid #00264D;background:#f0f4ff;}
.mp-tbl tfoot td{padding:10px 8px;font-weight:700;color:#00264D;font-size:12px;}
.mp-empty{text-align:center;padding:28px;color:#94a3b8;font-size:13px;}
.mp-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800;white-space:nowrap;}
.mp-badge.ok{background:#dcfce7;color:#15803d;}
.mp-badge.warn{background:#fef9c3;color:#854d0e;}
.mp-badge.bad{background:#fee2e2;color:#dc2626;}
.mp-badge.info{background:#dbeafe;color:#1d4ed8;}
.mp-mono{font-family:Consolas,Monaco,monospace;font-weight:700;color:#00264D;}
.mp-neg{color:#dc2626;font-weight:700;}
.mp-pos{color:#15803d;font-weight:700;}
@media(max-width:900px){.mp-summary-grid{grid-template-columns:repeat(2,minmax(130px,1fr));}.mp-export-actions{margin-left:0;width:100%;flex-wrap:wrap;}}
@media print{
    @page{
        size: A4 landscape;
        margin:.2in .3in;
        /* Suppress browser-injected URL/date headers and footers */
        @top-left{content:none;}
        @top-center{content:none;}
        @top-right{content:none;}
        @bottom-left{content:none;}
        @bottom-center{content:none;}
        @bottom-right{content:none;}
    }
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}

    /* Hide sidebar, header, nav and all UI chrome */
    html,body{background:#fff !important;padding:0 !important;margin:0 !important;width:100% !important;overflow:visible !important;}
    .sidebar,aside,.top-header,header,nav,.sidebar-identity-footer,
    .mp-filter-bar,.mp-tabs,.mp-export-actions,.mp-summary-grid{display:none !important;}

    /* Remove sidebar offset from main content area */
    .main,main{margin-left:0 !important;padding:0 !important;width:100% !important;max-width:100% !important;float:none !important;position:static !important;display:block !important;}

    /* Wrapper fills full page */
    .mp-wrapper{box-shadow:none !important;border-radius:0 !important;width:100% !important;overflow:visible !important;}
    .mp-content{padding:0 !important;width:100% !important;}

    /* Printable section */
    .mp-printable{display:block !important;overflow:visible !important;width:100% !important;}
    .mp-panel{display:none !important;}
    .mp-panel.active{display:block !important;width:100% !important;}

    /* Report header */
    .mp-rpt-header{break-after:avoid !important;page-break-after:avoid !important;text-align:center !important;width:100% !important;padding:6px 0 8px !important;}
    .rh-title,.rh-sub,.rh-station,.rh-date{text-align:center !important;display:block !important;}

    /* Table — squeeze to fit 1 page */
    .mp-table-wrap{overflow:visible !important;width:100% !important;}
    .mp-tbl{width:100% !important;min-width:0 !important;table-layout:auto !important;font-size:7px !important;break-inside:auto !important;page-break-inside:auto !important;margin:0 !important;}
    .mp-tbl thead{display:table-header-group !important;}
    .mp-tbl tfoot{display:table-footer-group !important;}
    .mp-tbl tr{break-inside:avoid !important;page-break-inside:avoid !important;}
    .mp-tbl th,.mp-tbl td{font-size:7px !important;padding:2px 3px !important;white-space:normal !important;word-break:break-word !important;line-height:1.2 !important;}
}
</style>

<div class="mp-wrapper">
    <form method="GET" class="mp-filter-bar">
        <input type="hidden" name="section" value="<?= mp_h($section) ?>">
        <label style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">From</label>
        <input type="date" name="date_from" value="<?= mp_h($date_from) ?>" required>
        <span style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">To</span>
        <input type="date" name="date_to" value="<?= mp_h($date_to) ?>" required>
        <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
        <div class="mp-export-actions">
            <button type="button" class="mp-export-btn" onclick="mpExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="button" class="mp-export-btn" onclick="mpExport('csv')"><i class="fas fa-file-csv"></i> CSV</button>
            <button type="button" class="mp-export-btn" onclick="mpExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
            <button type="button" class="mp-export-btn" onclick="mpPrint()"><i class="fas fa-print"></i> Print</button>
        </div>
    </form>

    <div class="mp-tabs">
        <button type="button" class="mp-tab <?= $section === 'purchase' ? 'active' : '' ?>" onclick="mpTab('purchase')"><i class="fas fa-chart-bar"></i> Purchase Report</button>
        <button type="button" class="mp-tab <?= $section === 'comparison' ? 'active' : '' ?>" onclick="mpTab('comparison')"><i class="fas fa-balance-scale"></i> PO vs Received</button>
        <button type="button" class="mp-tab <?= $section === 'stockin' ? 'active' : '' ?>" onclick="mpTab('stockin')"><i class="fas fa-box-open"></i> Stock-In Approval</button>
        <button type="button" class="mp-tab <?= $section === 'validation' ? 'active' : '' ?>" onclick="mpTab('validation')"><i class="fas fa-clipboard-check"></i> Delivery Validation</button>
        <button type="button" class="mp-tab <?= $section === 'variance' ? 'active' : '' ?>" onclick="mpTab('variance')"><i class="fas fa-exclamation-triangle"></i> Delivery Variance</button>
        <button type="button" class="mp-tab <?= $section === 'fuel_variance' ? 'active' : '' ?>" onclick="mpTab('fuel_variance')"><i class="fas fa-gas-pump"></i> Fuel Variance</button>
    </div>

    <div class="mp-content">
        <div class="mp-printable">

            <!-- ── PURCHASE REPORT PANEL ── -->
            <div class="mp-panel <?= $section === 'purchase' ? 'active' : '' ?>">
                <div class="mp-rpt-header">
                    <div class="rh-title">Purchase Report</div>
                    <div class="rh-sub">Purchase Order Summary — Merchandise &amp; Fuel</div>
                    <div class="rh-station"><?= mp_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= mp_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . mp_date($date_to) : '' ?></div>
                </div>



                <div class="mp-table-wrap">
                    <table class="mp-tbl" id="purchaseReportTable">
                        <thead><tr>
                            <th>PO No.</th><th>PO Date</th><th>Type</th><th>Supplier</th>
                            <th style="text-align:right;">Total Items</th>
                            <th style="text-align:right;">Total Qty</th>
                            <th style="text-align:right;">Total Amount</th>
                            <th>Requested By</th><th>Approved By</th><th>Status</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($purchase_rows)): ?>
                            <tr><td colspan="10" class="mp-empty">No purchase records found for this period.</td></tr>
                        <?php else: foreach ($purchase_rows as $r):
                            $po_key  = (string)$r['po_number'];
                            $po_items = $purchase_items_json[$po_key] ?? [];
                            $item_total_qty = 0;
                            $item_grand_total = 0.0;
                            foreach ($po_items as $it) {
                                $item_total_qty   += (float)$it['qty_ordered'];
                                $item_grand_total += (float)$it['total'];
                            }
                        ?>
                            <tr style="border-bottom:none;">
                                <td class="mp-mono"><?= mp_h($r['po_number']) ?></td>
                                <td><?= mp_date($r['po_date']) ?></td>
                                <td><?= mp_h($r['po_type']) ?></td>
                                <td><?= mp_h($r['supplier_name']) ?></td>
                                <td style="text-align:right;"><?= number_format((int)$r['total_items']) ?></td>
                                <td style="text-align:right;"><?= mp_qty($r['total_qty']) ?></td>
                                <td style="text-align:right;font-weight:700;"><?= mp_money($r['total_amount']) ?></td>
                                <td><?= mp_h($r['requested_by']) ?></td>
                                <td><?= mp_h($r['approved_by'] ?: '-') ?></td>
                                <td><span class="mp-badge <?= mp_status_class($r['status']) ?>"><?= mp_h($r['status']) ?></span></td>
                            </tr>
                            <!-- inline items row -->
                            <tr style="border-bottom:2px solid #e2e8f0;">
                                <td colspan="10" style="padding:0 8px 10px 32px; background:#f8fafc;">
                                    <?php if (!empty($po_items)): ?>
                                    <table style="width:96%;border-collapse:collapse;font-size:11px;margin:2px 0 0;">
                                        <thead>
                                            <tr style="background:#e8edf5;">
                                                <th style="padding:5px 8px;text-align:left;font-size:10px;color:#00264D;font-weight:800;text-transform:uppercase;letter-spacing:.3px;">Item / Product</th>
                                                <th style="padding:5px 8px;text-align:right;font-size:10px;color:#00264D;font-weight:800;text-transform:uppercase;letter-spacing:.3px;">Qty Ordered</th>
                                                <th style="padding:5px 8px;text-align:right;font-size:10px;color:#00264D;font-weight:800;text-transform:uppercase;letter-spacing:.3px;">Unit Cost</th>
                                                <th style="padding:5px 8px;text-align:right;font-size:10px;color:#00264D;font-weight:800;text-transform:uppercase;letter-spacing:.3px;">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($po_items as $it): ?>
                                            <tr style="border-bottom:1px solid #e9edf3;">
                                                <td style="padding:5px 8px;color:#334155;"><?= mp_h($it['item_name']) ?></td>
                                                <td style="padding:5px 8px;text-align:right;font-weight:600;"><?= mp_qty($it['qty_ordered']) ?></td>
                                                <td style="padding:5px 8px;text-align:right;"><?= mp_money($it['unit_cost']) ?></td>
                                                <td style="padding:5px 8px;text-align:right;font-weight:700;"><?= mp_money($it['total']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background:#e8f0fe;border-top:2px solid #00264D;">
                                                <td style="padding:5px 8px;font-weight:800;color:#00264D;">Grand Total</td>
                                                <td style="padding:5px 8px;text-align:right;font-weight:800;color:#00264D;"><?= mp_qty($item_total_qty) ?></td>
                                                <td></td>
                                                <td style="padding:5px 8px;text-align:right;font-weight:800;color:#00264D;"><?= mp_money($item_grand_total) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;padding:4px 8px;display:block;">No item details available.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($purchase_rows)): ?>
                        <tfoot><tr>
                            <td colspan="4">TOTAL: <?= number_format(count($purchase_rows)) ?> Purchase Order(s)</td>
                            <td style="text-align:right;"><?= number_format(array_sum(array_column($purchase_rows,'total_items'))) ?></td>
                            <td style="text-align:right;"><?= mp_qty($purchase_total_qty) ?></td>
                            <td style="text-align:right;"><?= mp_money($purchase_total_amount) ?></td>
                            <td colspan="3"></td>
                        </tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <!-- END PURCHASE REPORT PANEL -->

            <div class="mp-panel <?= $section === 'validation' ? 'active' : '' ?>">
                <div class="mp-rpt-header">
                    <div class="rh-title">Delivery Validation Report</div>
                    <div class="rh-sub">Staff-recorded deliveries for manager checking</div>
                    <div class="rh-station"><?= mp_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= mp_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . mp_date($date_to) : '' ?></div>
                </div>
                <div class="mp-table-wrap">
                    <table class="mp-tbl">
                        <thead><tr>
                            <th>Delivery Ref</th><th>PO No.</th><th>DR No.</th><th>Type</th><th>Supplier</th><th>Item</th>
                            <th>Expected</th><th>Received</th><th>Damaged</th><th>Delivery Date</th><th>Status</th><th>Recorded By</th><th>Manager Action</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($delivery_rows)): ?>
                            <tr><td colspan="13" class="mp-empty">No delivery records for this period.</td></tr>
                        <?php else: foreach ($delivery_rows as $r): ?>
                            <tr>
                                <td class="mp-mono"><?= mp_h($r['delivery_ref']) ?></td>
                                <td class="mp-mono"><?= mp_h($r['po_number']) ?></td>
                                <td><?= mp_h($r['dr_number']) ?></td>
                                <td><?= mp_h($r['delivery_type']) ?></td>
                                <td><?= mp_h($r['supplier_name']) ?></td>
                                <td><?= mp_h($r['item_name']) ?></td>
                                <td><?= mp_qty($r['expected_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_qty($r['received_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_qty($r['damaged_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_date($r['delivery_date']) ?></td>
                                <td><span class="mp-badge <?= mp_status_class($r['status']) ?>"><?= mp_h($r['status']) ?></span></td>
                                <td><?= mp_h($r['recorded_by']) ?></td>
                                <td><?= mp_datetime($r['manager_action_at']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($delivery_rows)): ?><tfoot><tr><td colspan="13">TOTAL DELIVERY ROWS: <?= number_format(count($delivery_rows)) ?></td></tr></tfoot><?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="mp-panel <?= $section === 'comparison' ? 'active' : '' ?>">
                <div class="mp-rpt-header">
                    <div class="rh-title">PO vs Received Report</div>
                    <div class="rh-sub">Ordered quantity compared with actual received quantity</div>
                    <div class="rh-station"><?= mp_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= mp_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . mp_date($date_to) : '' ?></div>
                </div>
                <div class="mp-table-wrap">
                    <table class="mp-tbl">
                        <thead><tr>
                            <th>PO No.</th><th>Type</th><th>Supplier</th><th>Item</th><th>Ordered</th><th>Received</th>
                            <th>Variance</th><th>Expected Date</th><th>Latest Delivery</th><th>Delivery Ref</th><th>Status</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($comparison_rows)): ?>
                            <tr><td colspan="11" class="mp-empty">No PO comparison records for this period.</td></tr>
                        <?php else: foreach ($comparison_rows as $r):
                            $variance = (float)$r['variance_qty'];
                            $variance_class = $variance < 0 ? 'mp-neg' : ($variance > 0 ? 'mp-pos' : '');
                        ?>
                            <tr>
                                <td class="mp-mono"><?= mp_h($r['po_number']) ?></td>
                                <td><?= mp_h($r['po_type']) ?></td>
                                <td><?= mp_h($r['supplier_name']) ?></td>
                                <td><?= mp_h($r['item_name']) ?></td>
                                <td><?= mp_qty($r['ordered_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_qty($r['received_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td class="<?= $variance_class ?>"><?= ($variance > 0 ? '+' : '') . mp_qty($variance) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_date($r['expected_delivery_date']) ?></td>
                                <td><?= mp_date($r['latest_delivery_date']) ?></td>
                                <td><?= mp_h($r['delivery_refs']) ?></td>
                                <td><span class="mp-badge <?= mp_status_class($r['comparison_status']) ?>"><?= mp_h($r['comparison_status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mp-panel <?= $section === 'stockin' ? 'active' : '' ?>">
                <div class="mp-rpt-header">
                    <div class="rh-title">Stock-In Approval Report</div>
                    <div class="rh-sub">Manager-approved stock added to inventory</div>
                    <div class="rh-station"><?= mp_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= mp_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . mp_date($date_to) : '' ?></div>
                </div>
                <div class="mp-table-wrap">
                    <table class="mp-tbl">
                        <thead><tr>
                            <th>Batch Ref</th><th>PO No.</th><th>DR / Invoice</th><th>Type</th><th>Item</th><th>Expected</th>
                            <th>Received</th><th>Variance</th><th>Condition</th><th>Selling Price</th><th>Approved By</th><th>Approved At</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($stockin_rows)): ?>
                            <tr><td colspan="12" class="mp-empty">No stock-in approvals for this period.</td></tr>
                        <?php else: foreach ($stockin_rows as $r):
                            $variance = (float)$r['variance_qty'];
                            $variance_class = $variance < 0 ? 'mp-neg' : ($variance > 0 ? 'mp-pos' : '');
                        ?>
                            <tr>
                                <td class="mp-mono"><?= mp_h($r['batch_ref']) ?></td>
                                <td class="mp-mono"><?= mp_h($r['po_number']) ?></td>
                                <td><?= mp_h($r['dr_number']) ?></td>
                                <td><?= mp_h($r['stock_type']) ?></td>
                                <td><?= mp_h($r['item_name']) ?></td>
                                <td><?= mp_qty($r['expected_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_qty($r['received_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td class="<?= $variance_class ?>"><?= ($variance > 0 ? '+' : '') . mp_qty($variance) ?> <?= mp_h($r['unit']) ?></td>
                                <td><span class="mp-badge <?= mp_status_class($r['condition_flag']) ?>"><?= mp_h($r['condition_flag']) ?></span></td>
                                <td><?= mp_money($r['selling_price']) ?></td>
                                <td><?= mp_h($r['approved_by']) ?></td>
                                <td><?= mp_datetime($r['encoded_at']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($stockin_rows)): ?><tfoot><tr><td colspan="12">TOTAL STOCK-IN ROWS: <?= number_format($summary_stockin) ?></td></tr></tfoot><?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="mp-panel <?= $section === 'variance' ? 'active' : '' ?>">
                <div class="mp-rpt-header">
                    <div class="rh-title">Delivery Variance Report</div>
                    <div class="rh-sub">Short, excess, pending, damaged, or discrepant deliveries</div>
                    <div class="rh-station"><?= mp_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= mp_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . mp_date($date_to) : '' ?></div>
                </div>
                <div class="mp-table-wrap">
                    <table class="mp-tbl">
                        <thead><tr>
                            <th>PO No.</th><th>Delivery Ref</th><th>Type</th><th>Supplier</th><th>Item</th>
                            <th>Expected</th><th>Received</th><th>Variance</th><th>Status</th><th>Notes</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($variance_rows)): ?>
                            <tr><td colspan="10" class="mp-empty">No variance records for this period.</td></tr>
                        <?php else: foreach ($variance_rows as $r):
                            $variance = (float)$r['variance_qty'];
                            $variance_class = $variance < 0 ? 'mp-neg' : ($variance > 0 ? 'mp-pos' : '');
                        ?>
                            <tr>
                                <td class="mp-mono"><?= mp_h($r['po_number']) ?></td>
                                <td><?= mp_h($r['delivery_ref']) ?></td>
                                <td><?= mp_h($r['type']) ?></td>
                                <td><?= mp_h($r['supplier_name']) ?></td>
                                <td><?= mp_h($r['item_name']) ?></td>
                                <td><?= mp_qty($r['expected_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td><?= mp_qty($r['received_qty']) ?> <?= mp_h($r['unit']) ?></td>
                                <td class="<?= $variance_class ?>"><?= ($variance > 0 ? '+' : '') . mp_qty($variance) ?> <?= mp_h($r['unit']) ?></td>
                                <td><span class="mp-badge <?= mp_status_class($r['status']) ?>"><?= mp_h($r['status']) ?></span></td>
                                <td><?= mp_h(strlen((string)$r['notes']) > 80 ? substr((string)$r['notes'], 0, 80) . '...' : $r['notes']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($variance_rows)): ?><tfoot><tr><td colspan="10">TOTAL VARIANCE ROWS: <?= number_format($summary_variance) ?></td></tr></tfoot><?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- â”€â”€ FUEL VARIANCE REPORT PANEL â”€â”€ -->
            <div class="mp-panel <?= $section === 'fuel_variance' ? 'active' : '' ?>">
                <div class="mp-rpt-header">
                    <div class="rh-title">Fuel Variance Report</div>
                    <div class="rh-sub">System Volume vs Actual Tank Dip Volume Comparison</div>
                    <div class="rh-station"><?= mp_h($station_name) ?></div>
                    <div class="rh-date"><strong>Date:</strong> <?= mp_date($date_from) ?><?= $date_from !== $date_to ? ' - ' . mp_date($date_to) : '' ?></div>
                </div>


                <div class="mp-table-wrap">
                    <table class="mp-tbl">
                        <thead>
                            <tr>
                                <th>Ref No.</th>
                                <th>Date</th>
                                <th>UGT No.</th>
                                <th>Fuel Type</th>
                                <th>System Volume (L)</th>
                                <th>Actual Tank Dip (L)</th>
                                <th>Variance (L)</th>
                                <th>Adjustment</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($fuel_variance_rows)): ?>
                            <tr><td colspan="11" class="mp-empty">No fuel variance records found for the selected period.</td></tr>
                        <?php else: foreach ($fuel_variance_rows as $r):
                            $var = (float)($r['variance_liters'] ?? 0);
                            $adj_liters = (float)($r['adjustment_liters'] ?? 0);
                            $adj_dir    = (string)($r['adj_direction'] ?? '');
                        ?>
                            <tr>
                                <td class="mp-mono" style="font-size:11px;color:#64748b;">#<?= mp_h((string)$r['ref_no']) ?></td>
                                <td><?= mp_date($r['report_date']) ?></td>
                                <td class="mp-mono"><?= mp_h($r['ugt_no']) ?></td>
                                <td><strong><?= mp_h($r['fuel_type']) ?></strong></td>
                                <td><?= number_format((float)$r['system_before'], 2) ?> L</td>
                                <td><?= number_format((float)$r['actual_dip'], 2) ?> L</td>
                                <td>
                                    <?php if ($var < 0): ?>
                                        <span class="mp-neg"><?= number_format($var, 2) ?> L</span>
                                    <?php elseif ($var > 0): ?>
                                        <span class="mp-pos">+<?= number_format($var, 2) ?> L</span>
                                    <?php else: ?>
                                        <span style="color:#64748b;">0.00 L</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($adj_liters != 0): ?>
                                        <span style="color:<?= $adj_dir === 'Increase' ? '#15803d' : '#dc2626' ?>; font-weight:700;">
                                            <?= $adj_dir === 'Increase' ? '+' : '-' ?><?= number_format(abs($adj_liters), 2) ?> L
                                        </span>
                                        <span style="font-size:10px;color:#64748b;display:block;"><?= mp_h($adj_dir) ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="mp-badge <?= mp_status_class($r['adj_status']) ?>"><?= mp_h($r['adj_status']) ?></span></td>
                                <td><?= mp_h($r['adj_reason'] !== '-' ? $r['adj_reason'] : '') ?></td>
                                <td style="max-width:180px;word-break:break-word;font-size:11px;color:#475569;"><?= mp_h(strlen((string)$r['remarks']) > 100 ? substr((string)$r['remarks'], 0, 100) . '...' : (string)$r['remarks']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($fuel_variance_rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="6">TOTAL FUEL VARIANCE ROWS: <?= count($fuel_variance_rows) ?></td>
                                <td colspan="5" style="text-align:right;">NET VARIANCE: <strong style="color:<?= $fv_total_variance < 0 ? '#dc2626' : ($fv_total_variance > 0 ? '#15803d' : '#00264D') ?>;"><?= ($fv_total_variance > 0 ? '+' : '') . number_format($fv_total_variance, 2) ?> L</strong></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ── PO Detail Drill-Down Modal ── -->
<div id="poDetailOverlay" onclick="closePoDetail()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:20000;"></div>
<div id="poDetailModal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:20001;background:#fff;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.25);width:90%;max-width:860px;max-height:85vh;overflow:hidden;flex-direction:column;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e2e8f0;background:#00264D;border-radius:10px 10px 0 0;">
        <div style="font-size:15px;font-weight:800;color:#fff;"><i class="fas fa-file-invoice"></i> Purchase Order Details</div>
        <button onclick="closePoDetail()" style="background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="overflow-y:auto;flex:1;padding:20px;">
        <!-- PO Info Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:18px;">
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">PO No.</div><div style="font-size:13px;font-weight:700;color:#00264D;font-family:monospace;" id="pod-po-number">—</div></div>
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Purchase Type</div><div style="font-size:13px;font-weight:700;color:#334155;" id="pod-type">—</div></div>
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">PO Date</div><div style="font-size:13px;font-weight:600;color:#334155;" id="pod-date">—</div></div>
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Supplier</div><div style="font-size:13px;font-weight:600;color:#334155;" id="pod-supplier">—</div></div>
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Requested By</div><div style="font-size:13px;font-weight:600;color:#334155;" id="pod-reqby">—</div></div>
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Approved By</div><div style="font-size:13px;font-weight:600;color:#334155;" id="pod-apprby">—</div></div>
            <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;">Status</div><div id="pod-status">—</div></div>
        </div>
        <!-- Ordered Items Table -->
        <div style="font-size:12px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><i class="fas fa-boxes"></i> Ordered Items</div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;" id="pod-items-table">
                <thead><tr style="background:#00264D;color:#fff;">
                    <th style="padding:8px 10px;text-align:left;">SKU / Item</th>
                    <th style="padding:8px 10px;text-align:right;">Qty Ordered</th>
                    <th style="padding:8px 10px;text-align:right;">Unit Cost</th>
                    <th style="padding:8px 10px;text-align:right;">Total</th>
                </tr></thead>
                <tbody id="pod-items-body"></tbody>
                <tfoot id="pod-items-foot" style="background:#f0f4ff;border-top:2px solid #00264D;"></tfoot>
            </table>
        </div>
    </div>
</div>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function mpTab(key) {
    const url = new URL(window.location.href);
    url.searchParams.set('section', key);
    const df = document.querySelector('input[name="date_from"]');
    const dt = document.querySelector('input[name="date_to"]');
    if (df) url.searchParams.set('date_from', df.value);
    if (dt) url.searchParams.set('date_to', dt.value);
    window.location.href = url.toString();
}

function mpTableToAoA(table) {
    const aoa = [];
    table.querySelectorAll('thead tr').forEach(tr => aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim())));
    table.querySelectorAll('tbody tr').forEach(tr => aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim())));
    table.querySelectorAll('tfoot tr').forEach(tr => aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim())));
    return aoa;
}

function mpExport(type) {
    const active = document.querySelector('.mp-panel.active');
    const table = active ? active.querySelector('table.mp-tbl') : null;
    if (!table) {
        alert('No report table found.');
        return;
    }
    const section = new URL(window.location).searchParams.get('section') || 'validation';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Manager_Procurement_Report_${section}_${dateFrom}_${dateTo}`;

    if (type === 'pdf') {
        exportPrintableAreaToPDF(active, 'Manager Procurement Report', filename, document.activeElement);
        return;
    }

    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }

    const aoa = mpTableToAoA(table);

    if (type === 'csv') {
        let csv = '';
        aoa.forEach(row => {
            csv += row.map(cell => '"' + String(cell).replace(/"/g, '""') + '"').join(',') + '\n';
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
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    if (aoa.length && aoa[0]) {
        ws['!cols'] = aoa[0].map((_, ci) => ({
            wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] || '').length)))
        }));
    }
    const title = active.querySelector('.rh-title')?.innerText?.trim() || 'Report';
    XLSX.utils.book_append_sheet(wb, ws, title.replace(/[:\\\/?*\[\]]/g, '').substring(0, 31) || 'Report');
    XLSX.writeFile(wb, filename + '.xlsx');
}

function mpPrint() {
    const active = document.querySelector('.mp-panel.active') || document.querySelector('.mp-printable');
    printReportArea(active);
}

function openPoDetail(el) {
    const d = el.dataset;
    document.getElementById('pod-po-number').textContent = d.po || '—';
    document.getElementById('pod-type').textContent = d.type || '—';
    document.getElementById('pod-date').textContent = d.date || '—';
    document.getElementById('pod-supplier').textContent = d.supplier || '—';
    document.getElementById('pod-reqby').textContent = d.reqby || '—';
    document.getElementById('pod-apprby').textContent = d.apprby || '—';
    const statusEl = document.getElementById('pod-status');
    const st = d.status || '';
    const sc = st.toLowerCase().includes('complet') || st.toLowerCase().includes('approv') ? '#15803d'
             : st.toLowerCase().includes('cancel') || st.toLowerCase().includes('reject') ? '#dc2626'
             : '#854d0e';
    statusEl.innerHTML = `<span style="background:${sc}20;color:${sc};border:1px solid ${sc}40;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:800;">${st}</span>`;

    let items = [];
    try { items = JSON.parse(d.items || '[]'); } catch(e) {}
    const tbody = document.getElementById('pod-items-body');
    const tfoot = document.getElementById('pod-items-foot');
    tbody.innerHTML = '';
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:#94a3b8;">No item details available.</td></tr>';
    } else {
        let grandQty = 0, grandAmt = 0;
        items.forEach((it, i) => {
            const qty = parseFloat(it.qty_ordered) || 0;
            const cost = parseFloat(it.unit_cost) || 0;
            const total = parseFloat(it.total) || (qty * cost);
            grandQty += qty;
            grandAmt += total;
            const bg = i % 2 === 0 ? '#fff' : '#f8fafc';
            tbody.innerHTML += `<tr style="background:${bg};border-bottom:1px solid #f1f5f9;">
                <td style="padding:8px 10px;color:#334155;">${it.item_name || '—'}</td>
                <td style="padding:8px 10px;text-align:right;font-weight:700;">${qty % 1 === 0 ? qty.toFixed(0) : qty.toFixed(2)}</td>
                <td style="padding:8px 10px;text-align:right;">&#8369;${cost.toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                <td style="padding:8px 10px;text-align:right;font-weight:700;">&#8369;${total.toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            </tr>`;
        });
        tfoot.innerHTML = `<tr>
            <td style="padding:8px 10px;font-weight:800;color:#00264D;">GRAND TOTAL</td>
            <td style="padding:8px 10px;text-align:right;font-weight:800;color:#00264D;">${grandQty % 1 === 0 ? grandQty.toFixed(0) : grandQty.toFixed(2)}</td>
            <td></td>
            <td style="padding:8px 10px;text-align:right;font-weight:800;color:#00264D;">&#8369;${grandAmt.toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
        </tr>`;
    }
    const overlay = document.getElementById('poDetailOverlay');
    const modal = document.getElementById('poDetailModal');
    overlay.style.display = 'block';
    modal.style.display = 'flex';
}

function closePoDetail() {
    document.getElementById('poDetailOverlay').style.display = 'none';
    document.getElementById('poDetailModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
