<?php
/**
 * STAFF REPORTS MODULE
 * Cleaned up to show ONLY tables and export options (Excel & PDF), strictly no summary cards.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$user_id    = (int)$me['id'];
$station_id = user_station_id();

if (!in_array($role, ['staff','manager','admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// ── Module gate
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

// Filters
$view      = is_array($_GET['view']      ?? '') ? 'daily_sales'                        : (string)($_GET['view']      ?? 'daily_sales');
$sub       = is_array($_GET['sub']       ?? '') ? 'all'                                 : (string)($_GET['sub']       ?? 'all');
$date_from = is_array($_GET['date_from'] ?? '') ? date('Y-m-d', strtotime('-1 year'))   : (string)($_GET['date_from'] ?? date('Y-m-d', strtotime('-1 year')));
$date_to   = is_array($_GET['date_to']   ?? '') ? date('Y-m-d')                         : (string)($_GET['date_to']   ?? date('Y-m-d'));
$export    = is_array($_GET['export']    ?? '') ? ''                                     : (string)($_GET['export']    ?? '');
if (!in_array($sub, ['all','merchandise','fuel'])) $sub = 'all';

// Station name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

// Helpers
function has_col(PDO $pdo, string $table, string $col): bool {
    try { $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'"); return $r && $r->rowCount() > 0; }
    catch (Exception $e) { return false; }
}
$jo_enc = has_col($pdo, 'job_orders', 'created_by') ? 'created_by' : 'user_id';

// =====================================================================================
// DATA FETCHING SECTION — each view has its own try/catch, no single point of failure
// =====================================================================================
$report_data  = [];
$_report_error = '';

// Safety check — if user not logged in properly, bail early
if (!$user_id || !$station_id) {
    $_report_error = "Session error: user_id=$user_id, station_id=$station_id. Please log out and log in again.";
} elseif ($view === 'daily_sales') {
    try {
        $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''), CONCAT('JO-',jo.id))" : "CONCAT('JO-',jo.id)";
        $cost_col  = has_col($pdo,'job_orders','total_cost')   ? 'COALESCE(jo.total_cost, jo.estimated_cost, 0)' : 'COALESCE(jo.estimated_cost, 0)';

        $s1 = $pdo->prepare("
            SELECT 'Merchandise' AS txn_type,
                   COALESCE(NULLIF(mt.transaction_id,''), CONCAT('MT-',mt.id)) AS txn_ref,
                   COALESCE(NULLIF(TRIM(mt.customer_name),''), 'Walk-in') AS customer_name,
                   COALESCE((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                             FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id),
                            mt.item_sku, '—') AS detail,
                   COALESCE(mt.payment_method,'—') AS payment_method,
                   COALESCE(mt.total_amount,0) AS amount,
                   COALESCE(mt.validation_status,'Pending') AS status,
                   mt.created_at AS txn_date
            FROM merchandise_transactions mt
            WHERE mt.station_id = ? AND mt.staff_id = ? AND DATE(mt.created_at) BETWEEN ? AND ?
        ");
        $s1->execute([$station_id, $user_id, $date_from, $date_to]);

        $s2 = $pdo->prepare("
            SELECT 'Job Order' AS txn_type,
                   $jo_id_col AS txn_ref,
                   COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                   COALESCE(jo.service_type,'—') AS detail,
                   COALESCE(jo.payment_method,'—') AS payment_method,
                   $cost_col AS amount,
                   COALESCE(jo.validation_status, jo.status, 'Pending') AS status,
                   jo.created_at AS txn_date
            FROM job_orders jo
            WHERE jo.station_id = ? AND jo.$jo_enc = ? AND DATE(jo.created_at) BETWEEN ? AND ?
        ");
        $s2->execute([$station_id, $user_id, $date_from, $date_to]);

        $report_data = array_merge($s1->fetchAll(PDO::FETCH_ASSOC), $s2->fetchAll(PDO::FETCH_ASSOC));
        usort($report_data, fn($a,$b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'personal_activity') {
    try {
        $active_dates = [];
        $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM merchandise_transactions WHERE station_id=? AND staff_id=? AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$station_id, $user_id, $date_from, $date_to]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;

        $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM job_orders WHERE station_id=? AND $jo_enc=? AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$station_id, $user_id, $date_from, $date_to]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;

        try {
            $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM deliveries_oversight WHERE station_id=? AND encoded_by=? AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$station_id, $user_id, $date_from, $date_to]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
        } catch (Exception $e) {}

        try {
            $s = $pdo->prepare("SELECT DISTINCT DATE(encoded_at) AS d FROM fuel_readings WHERE station_id=? AND encoded_by=? AND DATE(encoded_at) BETWEEN ? AND ?");
            $s->execute([$station_id, $user_id, $date_from, $date_to]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
        } catch (Exception $e) {}

        try {
            $s = $pdo->prepare("SELECT DISTINCT DATE(created_at) AS d FROM audit_logs WHERE user_id=? AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$user_id, $date_from, $date_to]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) $active_dates[$d] = true;
        } catch (Exception $e) {}

        krsort($active_dates);
        foreach (array_keys($active_dates) as $d) {
            $q1 = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND staff_id=? AND DATE(created_at)=?");
            $q1->execute([$station_id, $user_id, $d]);
            $txn_count = (int)$q1->fetchColumn();

            $q2 = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND $jo_enc=? AND DATE(created_at)=?");
            $q2->execute([$station_id, $user_id, $d]);
            $jo_count = (int)$q2->fetchColumn();

            $del_count = 0;
            try {
                $q3 = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND encoded_by=? AND DATE(created_at)=?");
                $q3->execute([$station_id, $user_id, $d]);
                $del_count = (int)$q3->fetchColumn();
            } catch (Exception $e) {}

            $fuel_count = 0;
            try {
                $q4 = $pdo->prepare("SELECT COUNT(*) FROM fuel_readings WHERE station_id=? AND encoded_by=? AND DATE(encoded_at)=?");
                $q4->execute([$station_id, $user_id, $d]);
                $fuel_count = (int)$q4->fetchColumn();
            } catch (Exception $e) {}

            $audit_count = 0;
            try {
                $q5 = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND DATE(created_at)=?");
                $q5->execute([$user_id, $d]);
                $audit_count = (int)$q5->fetchColumn();
            } catch (Exception $e) {}

            $report_data[] = [
                'date'          => $d,
                'merch_txns'    => $txn_count,
                'job_orders'    => $jo_count,
                'fuel_readings' => $fuel_count,
                'deliveries'    => $del_count,
                'audit_actions' => $audit_count,
            ];
        }
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'customer_report') {
    try {
        $cost_col  = has_col($pdo,'job_orders','total_cost') ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
        $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''),jo.job_order_number,CONCAT('JO-',jo.id))" : "COALESCE(jo.job_order_number,CONCAT('JO-',jo.id))";

        $s1 = $pdo->prepare("
            SELECT $jo_id_col AS reference, 'Job Order' AS txn_type,
                   COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                   COALESCE(jo.vehicle_plate,'—') AS vehicle_plate,
                   COALESCE(jo.service_type,'—') AS service_detail,
                   COALESCE(jo.payment_method,'—') AS payment_method,
                   $cost_col AS amount,
                   COALESCE(jo.status,'—') AS workflow_status,
                   COALESCE(jo.validation_status,'—') AS validation_status,
                   jo.created_at
            FROM job_orders jo
            WHERE jo.station_id=? AND jo.$jo_enc=? AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $s1->execute([$station_id, $user_id, $date_from, $date_to]);

        $s2 = $pdo->prepare("
            SELECT COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS reference,
                   'Merchandise' AS txn_type,
                   COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer_name,
                   '—' AS vehicle_plate,
                   COALESCE((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                             FROM merchandise_transaction_items i WHERE i.transaction_id=mt.id),
                            mt.item_sku,'—') AS service_detail,
                   COALESCE(mt.payment_method,'—') AS payment_method,
                   COALESCE(mt.total_amount,0) AS amount,
                   COALESCE(mt.validation_status,'Pending') AS workflow_status,
                   COALESCE(mt.validation_status,'Pending') AS validation_status,
                   mt.created_at
            FROM merchandise_transactions mt
            WHERE mt.station_id=? AND mt.staff_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
            ORDER BY mt.created_at DESC
        ");
        $s2->execute([$station_id, $user_id, $date_from, $date_to]);

        $report_data = array_merge($s1->fetchAll(PDO::FETCH_ASSOC), $s2->fetchAll(PDO::FETCH_ASSOC));
        usort($report_data, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'inventory_report') {
    $sr_rows = []; $si_rows = []; $fsr_rows = []; $fd_rows = []; $fr_rows = [];

    try {
        $sr_staff_col    = has_col($pdo,'stock_requests','staff_id') ? 'staff_id' : (has_col($pdo,'stock_requests','requested_by') ? 'requested_by' : 'user_id');
        $sr_prod_col     = has_col($pdo,'stock_requests','item_name') ? 'sr.item_name' : (has_col($pdo,'stock_requests','product_name') ? 'sr.product_name' : "COALESCE(ip.product_name,'Unknown')");
        $sr_qty_col      = has_col($pdo,'stock_requests','requested_quantity') ? 'sr.requested_quantity' : (has_col($pdo,'stock_requests','quantity') ? 'sr.quantity' : 'sr.qty');
        $sr_approved_col = has_col($pdo,'stock_requests','approved_quantity') ? 'sr.approved_quantity' : 'NULL';
        $sr_notes_col    = has_col($pdo,'stock_requests','remarks') ? 'sr.remarks' : (has_col($pdo,'stock_requests','notes') ? 'sr.notes' : "''");
        $sr_sku_col      = has_col($pdo,'stock_requests','item_sku') ? 'sr.item_sku' : (has_col($pdo,'stock_requests','sku') ? 'sr.sku' : "''");
        $sr_cat_col      = has_col($pdo,'stock_requests','item_category') ? 'sr.item_category' : (has_col($pdo,'stock_requests','category') ? 'sr.category' : "''");

        $stmt = $pdo->prepare("
            SELECT CONCAT('SR-',sr.id) AS reference, 'Merchandise' AS type, 'Stock Request' AS record_type,
                   $sr_prod_col AS product_name, $sr_sku_col AS sku, $sr_cat_col AS category,
                   CONCAT(CASE WHEN $sr_qty_col>0 THEN $sr_qty_col ELSE COALESCE($sr_approved_col,0) END,' pcs') AS quantity,
                   sr.status, sr.created_at AS encoded_at, $sr_notes_col AS remarks
            FROM stock_requests sr LEFT JOIN inventory_products ip ON ip.id=sr.item_id
            WHERE sr.station_id=? AND sr.$sr_staff_col=? AND DATE(sr.created_at) BETWEEN ? AND ?
            ORDER BY sr.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $sr_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT CONCAT('MSI-',si.id) AS reference, 'Merchandise' AS type, 'Stock-In' AS record_type,
                   COALESCE(si.product_name,'—') AS product_name, COALESCE(si.sku,'—') AS sku,
                   COALESCE(si.category,'—') AS category,
                   CONCAT(COALESCE(si.qty_received,0),' pcs') AS quantity,
                   'Done' AS status, si.encoded_at, COALESCE(si.remarks,'') AS remarks
            FROM merchandise_stock_in si
            WHERE si.station_id=? AND si.encoded_by=? AND DATE(si.encoded_at) BETWEEN ? AND ?
            ORDER BY si.encoded_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $si_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $fsr_qty_col      = has_col($pdo,'fuel_stock_requests','requested_liters') ? 'fsr.requested_liters' : (has_col($pdo,'fuel_stock_requests','quantity') ? 'fsr.quantity' : '0');
        $fsr_approved_col = has_col($pdo,'fuel_stock_requests','approved_liters') ? 'fsr.approved_liters' : 'NULL';
        $fsr_notes_col    = has_col($pdo,'fuel_stock_requests','remarks') ? 'fsr.remarks' : (has_col($pdo,'fuel_stock_requests','notes') ? 'fsr.notes' : "''");
        $stmt = $pdo->prepare("
            SELECT CONCAT('FSR-',fsr.id) AS reference, 'Fuel' AS type, 'Fuel Stock Request' AS record_type,
                   COALESCE(fsr.fuel_type,'—') AS product_name, '—' AS sku, 'Fuel' AS category,
                   CONCAT(CASE WHEN $fsr_qty_col>0 THEN $fsr_qty_col ELSE COALESCE($fsr_approved_col,0) END,' L') AS quantity,
                   fsr.status, fsr.created_at AS encoded_at, $fsr_notes_col AS remarks
            FROM fuel_stock_requests fsr
            WHERE fsr.station_id=? AND fsr.staff_id=? AND DATE(fsr.created_at) BETWEEN ? AND ?
            ORDER BY fsr.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $fsr_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT CONCAT('FD-',fd.id) AS reference, 'Fuel' AS type, 'Fuel Delivery' AS record_type,
                   COALESCE(ft.name,fd.fuel_type,'—') AS product_name, '—' AS sku, 'Fuel' AS category,
                   CONCAT(COALESCE(fd.delivery_liters,0),' L') AS quantity,
                   COALESCE(fd.status,'—') AS status, fd.created_at AS encoded_at,
                   COALESCE(fd.notes,'') AS remarks
            FROM fuel_deliveries fd LEFT JOIN fuel_types ft ON ft.id=fd.fuel_type
            WHERE fd.station_id=? AND fd.received_by=? AND DATE(fd.created_at) BETWEEN ? AND ?
            ORDER BY fd.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $fd_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT CONCAT('FR-',fr.id) AS reference, 'Fuel' AS type, 'Fuel Reading' AS record_type,
                   CONCAT(COALESCE(fr.fuel_type,'—'),' (Pump ',fr.pump_number,')') AS product_name,
                   '—' AS sku, 'Fuel' AS category,
                   CONCAT(COALESCE(fr.difference,0),' L sold') AS quantity,
                   COALESCE(fr.status,'—') AS status, fr.encoded_at,
                   COALESCE(fr.shift_period,'') AS remarks
            FROM fuel_readings fr
            WHERE fr.station_id=? AND fr.encoded_by=? AND DATE(fr.encoded_at) BETWEEN ? AND ?
            ORDER BY fr.encoded_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $fr_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $all_inv = array_merge($sr_rows, $si_rows, $fsr_rows, $fd_rows, $fr_rows);
    usort($all_inv, fn($a,$b) => strtotime($b['encoded_at']) - strtotime($a['encoded_at']));

    if ($sub === 'fuel')            $report_data = array_values(array_filter($all_inv, fn($r) => $r['type'] === 'Fuel'));
    elseif ($sub === 'merchandise') $report_data = array_values(array_filter($all_inv, fn($r) => $r['type'] === 'Merchandise'));
    else                            $report_data = $all_inv;

} elseif ($view === 'jo_tracker') {
    try {
        $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''),CONCAT('JO-',jo.id))" : "CONCAT('JO-',jo.id)";
        $cost_col  = has_col($pdo,'job_orders','total_cost') ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
        $pay_col   = has_col($pdo,'job_orders','payment_status') ? "COALESCE(jo.payment_status,'Unpaid')" : "'Unpaid'";
        $stmt = $pdo->prepare("
            SELECT $jo_id_col AS job_order_id, COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                   COALESCE(jo.vehicle_plate,'—') AS vehicle_plate, COALESCE(jo.service_type,'—') AS service_type,
                   COALESCE(jo.status,'Pending') AS workflow_status, $pay_col AS payment_status,
                   $cost_col AS total_cost, jo.created_at
            FROM job_orders jo
            WHERE jo.station_id=? AND jo.$jo_enc=? AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'meter_readings') {
    try {
        $stmt = $pdo->prepare("
            SELECT r.id AS reading_id, COALESCE(p.pump_name,CONCAT('Pump ',r.pump_number)) AS pump_name,
                   r.fuel_type, COALESCE(r.shift_period,'—') AS shift,
                   r.previous_reading AS opening_reading, r.present_reading AS closing_reading,
                   r.difference AS liters_sold, r.status, r.encoded_at AS transaction_date
            FROM fuel_readings r LEFT JOIN fuel_pumps p ON r.pump_number=p.id
            WHERE r.station_id=? AND DATE(r.encoded_at) BETWEEN ? AND ?
            ORDER BY r.encoded_at DESC
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'fuel_deliveries') {
    try {
        $stmt = $pdo->prepare("
            SELECT CONCAT('FD-',fd.id) AS delivery_ref, fd.supplier,
                   COALESCE(ft.name,fd.fuel_type,'Unknown Fuel') AS product,
                   fd.delivery_liters AS quantity, 'Liters' AS unit, fd.status, fd.created_at
            FROM fuel_deliveries fd LEFT JOIN fuel_types ft ON fd.fuel_type=ft.id
            WHERE fd.station_id=? AND DATE(fd.created_at) BETWEEN ? AND ?
            ORDER BY fd.created_at DESC
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'merch_deliveries') {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(batch_id,delivery_ref) AS identifier,
                   supplier, product, quantity, unit, status, created_at
            FROM deliveries_oversight
            WHERE station_id=? AND encoded_by=? AND delivery_type='merchandise' AND DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'inventory_movement') {
    try {
        $stmt = $pdo->prepare("
            SELECT il.action, COALESCE(p.product_name,'Unknown Product') AS product_name,
                   il.quantity_change, il.reference_type, il.created_at
            FROM inventory_logs il LEFT JOIN inventory_products p ON il.product_id=p.id
            WHERE il.station_id=? AND DATE(il.created_at) BETWEEN ? AND ?
            ORDER BY il.created_at DESC
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'payment_status') {
    try {
        $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''),CONCAT('JO-',jo.id))" : "CONCAT('JO-',jo.id)";
        $cost_col  = has_col($pdo,'job_orders','total_cost') ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
        $pay_col   = has_col($pdo,'job_orders','payment_status') ? "COALESCE(jo.payment_status,'Unpaid')" : "'Unpaid'";
        $stmt = $pdo->prepare("
            SELECT 'Job Order' AS entity_type, $jo_id_col AS reference_id,
                   COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                   $pay_col AS payment_status, $cost_col AS total_amount, jo.created_at
            FROM job_orders jo
            WHERE jo.station_id=? AND jo.$jo_enc=? AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'customer_linkage') {
    try {
        $jo_id_col = has_col($pdo,'job_orders','job_order_id') ? "COALESCE(NULLIF(jo.job_order_id,''),CONCAT('JO-',jo.id))" : "CONCAT('JO-',jo.id)";
        $stmt = $pdo->prepare("
            SELECT $jo_id_col AS transaction_id, 'Job Order' AS transaction_type,
                   c.name AS linked_customer, jo.created_at
            FROM job_orders jo JOIN customers c ON jo.customer_id=c.id
            WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }

} elseif ($view === 'audit_trail') {
    try {
        $stmt = $pdo->prepare("
            SELECT action_type, action_details, entity_type, status, created_at
            FROM audit_logs
            WHERE user_id=? AND DATE(created_at) BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$user_id, $date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $_report_error = $e->getMessage(); }
}

// =====================================================================================
// EXPORT HANDLERS (EXCEL & PDF)
// =====================================================================================// EXPORT HANDLERS (EXCEL & PDF)
// =====================================================================================
$all_report_titles = [
    'daily_sales'       => 'Transaction Report',
    'personal_activity' => 'Personal Activity Report',
    'customer_report'   => 'Customer Report',
    'inventory_report'  => 'Inventory Report',
    'jo_tracker'        => 'Job Order Tracker Report',
    'meter_readings'    => 'Meter Reading Report',
    'fuel_deliveries'   => 'Fuel Deliveries Report',
    'merch_deliveries'  => 'Merchandise Deliveries Report',
    'inventory_movement'=> 'Inventory Movement Report',
    'payment_status'    => 'Payment Status Report',
    'customer_linkage'  => 'Customer Transaction Linkage Report',
    'audit_trail'       => 'Audit Trail Report',
];

// Staff sees exactly 5 reports
$staff_report_keys = ['daily_sales', 'personal_activity', 'customer_report', 'inventory_report', 'payment_status'];

// Dynamic filtering based on role/permissions
$report_titles = [];
if (in_array($role, ['manager', 'admin', 'superadmin'])) {
    $report_titles = $all_report_titles;
} else {
    foreach ($staff_report_keys as $k) {
        if (isset($all_report_titles[$k])) {
            $report_titles[$k] = $all_report_titles[$k];
        }
    }
}

// If selected view is not allowed for current role, fallback to first allowed view
if (!array_key_exists($view, $report_titles)) {
    $view = !empty($report_titles) ? array_key_first($report_titles) : 'daily_sales';
}

$report_title_display = $report_titles[$view] ?? 'Staff Report';
// Append sub-tab label to title when filtered
if ($sub === 'fuel') $report_title_display .= ' — Fuel';
elseif ($sub === 'merchandise') $report_title_display .= ' — Merchandise';

if ($export !== '') {
    // Generate HTML Table string dynamically based on the view
    $html_table = '<table><thead><tr>';
    
    // Define headers based on view
    $headers = [];
    if (empty($report_data)) {
        $headers = ['No Data Available'];
    } else {
        $headers = array_map(function($key) {
            return ucwords(str_replace('_', ' ', $key));
        }, array_keys($report_data[0]));
    }
    
    // Add # column
    $html_table .= '<th>#</th>';
    foreach ($headers as $h) { $html_table .= "<th>$h</th>"; }
    $html_table .= '</tr></thead><tbody>';
    
    $i = 1;
    foreach ($report_data as $row) {
        $html_table .= '<tr>';
        $html_table .= "<td>{$i}</td>";
        foreach ($row as $val) {
            $html_table .= "<td>" . htmlspecialchars((string)$val) . "</td>";
        }
        $html_table .= '</tr>';
        $i++;
    }
    if (empty($report_data)) {
        $html_table .= '<tr><td colspan="10" style="text-align:center;">No records found for this period.</td></tr>';
    }
    $html_table .= '</tbody></table>';

    if ($export === 'excel') {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"{$view}_" . date("Y-m-d") . ".xls\"");
        header("Pragma: no-cache"); header("Expires: 0");
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:Arial,sans-serif;font-size:11px;}
        .hdr{background:#002F6C;color:#fff;font-size:14px;font-weight:bold;padding:10px 14px;}
        .meta{background:#f0f4ff;font-size:10px;padding:6px 14px;color:#334155;}
        table{border-collapse:collapse;width:100%;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:7px 10px;font-size:10px;text-align:left;border:1px solid #001a4d;}
        td{padding:6px 10px;font-size:10px;border:1px solid #e2e8f0;}
        tr:nth-child(even) td{background:#f8fafc;}
        </style></head><body>';
        echo "<div class=\"hdr\">PETRON STATION MANAGEMENT SYSTEM &mdash; {$report_title_display}</div>";
        echo "<div class=\"meta\">Station: ".htmlspecialchars($station_name)." &nbsp;|&nbsp; Staff: ".htmlspecialchars($me["name"])." &nbsp;|&nbsp; Period: {$date_from} to {$date_to} &nbsp;|&nbsp; Generated: ".date("M j, Y h:i A")."</div>";
        echo $html_table;
        echo '</body></html>';
        exit;
    }
    
    if ($export === 'pdf') {
        header("Content-Type: text/html; charset=utf-8");
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$report_title_display.'</title><style>
        @page{margin:12mm;}
        body{font-family:Arial,sans-serif;font-size:9px;margin:0;color:#1e293b;}
        .hdr{background:#002F6C;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;}
        .hdr-title{font-size:13px;font-weight:bold;}.hdr-sub{font-size:9px;opacity:.85;}
        .meta{background:#f0f4ff;padding:6px 14px;font-size:9px;color:#334155;border-bottom:2px solid #002F6C;}
        table{width:100%;border-collapse:collapse;margin-top:8px;}
        th{background:#002F6C;color:#fff;padding:5px 7px;font-size:8px;text-align:left;}
        td{padding:4px 7px;font-size:8px;border-bottom:1px solid #f1f5f9;}
        tr:nth-child(even) td{background:#f8fafc;}
        .footer{margin-top:10px;font-size:8px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:6px;}
        @media print{.no-print{display:none;}}
        </style></head><body>';
        echo '<div class="hdr"><div><div class="hdr-title">PETRON STATION MANAGEMENT SYSTEM</div><div class="hdr-sub">'.$report_title_display.'</div></div><div style="text-align:right;font-size:9px;opacity:.85;">Generated: '.date("M j, Y h:i A").'</div></div>';
        echo '<div class="meta"><b>Station:</b> '.htmlspecialchars($station_name).' &nbsp;&nbsp; <b>Staff:</b> '.htmlspecialchars($me["name"]).' &nbsp;&nbsp; <b>Period:</b> '.$date_from.' &mdash; '.$date_to.'</div>';
        echo $html_table;
        echo '<div class="footer">Petron Station Management System &mdash; Printed: '.date("M j, Y h:i A").'</div>';
        echo '<div class="no-print" style="text-align:center;margin-top:16px;"><button onclick="window.print()" style="padding:10px 24px;background:#002F6C;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Print / Save as PDF</button></div>';
        echo '</body></html>';
        exit;
    }
}

// =====================================================================================
// PAGINATION LOGIC (Only for UI, not export)
// =====================================================================================
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$page  = isset($_GET['page'])  ? max(1, (int)$_GET['page']) : 1;

$total_records = count($report_data);
$total_pages   = ceil($total_records / $limit);
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
}
$offset = ($page - 1) * $limit;
$paginated_data = array_slice($report_data, $offset, $limit);

// =====================================================================================
// UI RENDER
// =====================================================================================
include __DIR__ . '/../partials/header.php';
?>

<style>
main.main, .main-content { padding-top: 0 !important; }
.sr-wrap { padding: 0 20px 100px 20px; max-width: 1300px; margin: 0 auto; }
.page-head { margin-top: 0 !important; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }

.filter-bar { background: #fff; padding: 16px; border: 1px solid #EAEAEA; border-radius: 12px; display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-group label { font-size: 11px; font-weight: 700; color: #667085; text-transform: uppercase; letter-spacing: 0.5px; }
.filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #EAEAEA; border-radius: 6px; font-size: 13px; outline: none; }
.filter-group input:focus, .filter-group select:focus { border-color: #00264D; }

.btn-filter { background: #00264D; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.2s; }
.btn-filter:hover { background: #001a36; }

.action-buttons { display: flex; gap: 10px; margin-left: auto; }
.btn-export-excel { background: #15803d; color: #fff; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
.btn-export-excel:hover { background: #166534; }
.btn-export-pdf { background: #b91c1c; color: #fff; padding: 9px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
.btn-export-pdf:hover { background: #991b1b; }

.table-container { background: #fff; border: 1px solid #EAEAEA; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.02); overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.data-table th { background: #f9fafb; padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; border-bottom: 2px solid #e5e7eb; }
.data-table td { padding: 12px 16px; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6; }
.data-table tr:hover td { background: #f9fafb; }
.data-table tr:last-child td { border-bottom: none; }

.empty-state { padding: 60px 20px; text-align: center; color: #9ca3af; }
.empty-state i { font-size: 40px; margin-bottom: 16px; opacity: 0.5; }

/* Sub-tabs */
.sub-tabs { display: flex; gap: 4px; margin-bottom: 16px; background: #f1f5f9; padding: 4px; border-radius: 10px; width: fit-content; }
.sub-tab { padding: 7px 20px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; color: #64748b; transition: 0.15s; border: none; background: transparent; }
.sub-tab:hover { color: #00264D; background: #e2e8f0; }
.sub-tab.active { background: #fff; color: #00264D; box-shadow: 0 1px 4px rgba(0,0,0,0.10); }

.pagination-wrapper { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #fff; border: 1px solid #EAEAEA; border-radius: 12px; margin-top: 12px; margin-bottom: 40px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); flex-wrap: wrap; gap: 10px; }
.rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7280; }
.rows-per-page select { padding: 6px; border: 1px solid #e5e7eb; border-radius: 4px; outline: none; cursor: pointer; }
.page-info { font-size: 13px; color: #6b7280; }
.pagination-controls { display: flex; align-items: center; gap: 10px; }
.btn-page { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; color: #374151; text-decoration: none; transition: 0.2s; }
.btn-page:not(.disabled):hover { background: #f3f4f6; color: #00264D; }
.btn-page.disabled { opacity: 0.5; cursor: not-allowed; }
.current-page { font-size: 13px; font-weight: 600; color: #374151; }
</style>

<div class="sr-wrap">
    <div class="page-head">
        <div>
            <h1 class="h1"><i class="fas fa-file-alt"></i> Staff Reports</h1>
            <div class="sub">
                <?php
                if ($view === 'payment_status') {
                    echo 'View downpayment, pending, and utang accounts for customers.';
                } else {
                    echo 'View and export your encoded records';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <form class="filter-bar" method="GET">
        <input type="hidden" name="limit" value="<?php echo htmlspecialchars((string)$limit); ?>">
        <div class="filter-group">
            <label>Report Type</label>
            <select name="view">
                <?php foreach($report_titles as $key => $title): ?>
                    <option value="<?php echo $key; ?>" <?php echo $view === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div class="filter-group">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div class="filter-group">
            <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Generate Report</button>
        </div>
        
        <div class="action-buttons">
            <a href="?view=<?php echo urlencode($view); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&sub=<?php echo urlencode($sub); ?>&export=excel" class="btn-export-excel">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <a href="?view=<?php echo urlencode($view); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&sub=<?php echo urlencode($sub); ?>&export=pdf" class="btn-export-pdf" target="_blank">
                <i class="fas fa-file-pdf"></i> Print / PDF
            </a>
        </div>
    </form>

    <!-- SUB-TABS (only for Inventory Report — fuel vs merchandise split) -->
    <?php if ($view === 'inventory_report'): ?>
    <?php
        $sub_base = '?view='.urlencode($view).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).'&limit='.$limit;
        $sub_labels = ['all' => '<i class="fas fa-layer-group"></i> All', 'merchandise' => '<i class="fas fa-box"></i> Merchandise', 'fuel' => '<i class="fas fa-gas-pump"></i> Fuel'];
    ?>
    <div class="sub-tabs">
        <?php foreach ($sub_labels as $sk => $sl): ?>
            <a href="<?php echo $sub_base.'&sub='.urlencode($sk); ?>" class="sub-tab <?php echo $sub === $sk ? 'active' : ''; ?>">
                <?php echo $sl; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- DATA TABLE -->
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <?php if (!empty($report_data)): ?>
                        <?php foreach (array_keys($report_data[0]) as $col): ?>
                            <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $col))); ?></th>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <th>Data</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($paginated_data)): ?>
                    <tr>
                        <td colspan="20">
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <p>No records found for the selected period.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $i = $offset + 1; foreach ($paginated_data as $row): ?>
                        <tr>
                            <td style="color:#6b7280; font-weight:600;"><?php echo $i++; ?></td>
                            <?php foreach ($row as $key => $val): ?>
                                <td>
                                    <?php 
                                        if ($key === 'type' || $key === 'txn_type') {
                                            $v_lower = strtolower($val);
                                            if ($v_lower === 'fuel') {
                                                $bg = '#fef3c7'; $clr = '#92400e';
                                            } elseif (in_array($v_lower, ['merchandise', 'job order'])) {
                                                $bg = '#dbeafe'; $clr = '#1e40af';
                                            } else {
                                                $bg = '#f3f4f6'; $clr = '#374151';
                                            }
                                            echo '<span style="background:'.$bg.';color:'.$clr.';padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">'.htmlspecialchars((string)$val).'</span>';
                                        } elseif (strpos($key, 'amount') !== false || strpos($key, 'cost') !== false) {
                                            echo '<strong>₱' . number_format((float)$val, 2) . '</strong>';
                                        } else if (strpos($key, 'status') !== false) {
                                            $color = in_array(strtolower($val), ['completed', 'approved', 'paid', 'done', 'verified']) ? '#15803d' : '#856404';
                                            echo '<span style="color:'.$color.'; font-weight:700;">'.htmlspecialchars((string)$val).'</span>';
                                        } else {
                                            echo htmlspecialchars((string)$val);
                                        }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="pagination-wrapper">
        <div class="rows-per-page">
            <label>Rows per page:</label>
            <select onchange="changeLimit(this)">
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
        <div class="page-info">
            Showing <?php echo $total_records > 0 ? $offset + 1 : 0; ?> to <?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> entries
        </div>
        <div class="pagination-controls">
            <?php if ($page > 1): ?>
                <a href="?view=<?php echo urlencode($view); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&sub=<?php echo urlencode($sub); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page - 1; ?>" class="btn-page" title="Previous Page"><i class="fas fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="btn-page disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>
            
            <span class="current-page">Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?></span>
            
            <?php if ($page < $total_pages): ?>
                <a href="?view=<?php echo urlencode($view); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&sub=<?php echo urlencode($sub); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page + 1; ?>" class="btn-page" title="Next Page"><i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="btn-page disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function changeLimit(select) {
    let url = new URL(window.location.href);
    url.searchParams.set('limit', select.value);
    url.searchParams.set('page', 1);
    // preserve sub param
    if (!url.searchParams.has('sub')) url.searchParams.set('sub', 'all');
    window.location.href = url.toString();
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
