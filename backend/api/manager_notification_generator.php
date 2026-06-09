<?php
/**
 * Manager Notification Generator
 * backend/api/manager_notification_generator.php
 *
 * Deeper, manager-scoped notifications. Covers all 8 modules with
 * approval/oversight triggers that staff don't see.
 *
 * Sources:
 *  1. Transactions  — pending approval, completed by staff
 *  2. Job Orders    — awaiting validation, completed by staff
 *  3. Fuel Mgmt     — low tank levels, refill requests, invalid readings
 *  4. Inventory     — threshold crossed, encoding errors flagged
 *  5. Customers     — pending ID validation, invalid upload format
 *  6. Deliveries    — en route, delayed, awaiting manager action
 *  7. Calendar      — shift conflicts, new schedule entries
 *  8. Reports       — weekly/monthly reports ready
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

if ($role !== 'manager') {
    echo json_encode(['ok' => false, 'error' => 'Manager only']); exit;
}

$station_id = (int)($me['station_id'] ?? 0);
$sw = $station_id ? $station_id : 0; // shorthand for inline use

// ── Ensure notifications table exists ────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        type         ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
        title        VARCHAR(255) NOT NULL,
        message      TEXT NOT NULL,
        event_type   VARCHAR(80) NOT NULL DEFAULT 'general',
        severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
        source_key   VARCHAR(200) NULL,
        redirect_url VARCHAR(500) NULL,
        status       ENUM('unread','read') NOT NULL DEFAULT 'unread',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at      TIMESTAMP NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_event_type  (event_type),
        INDEX idx_source_key  (source_key),
        INDEX idx_created_at  (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$generated = 0;

function mgr_push(
    PDO    $pdo,
    int    $user_id,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = ''
): int {
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO notifications
            (user_id, type, event_type, severity, title, message, source_key, redirect_url, status)
         SELECT ?, ?, ?, ?, ?, ?, ?, ?, 'unread'
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM notifications
             WHERE user_id = ? AND source_key = ?
         )"
    );
    $stmt->execute([
        $user_id, $type, $event_type, $severity, $title, $message,
        $source_key, $redirect_url,
        $user_id, $source_key
    ]);
    return $stmt->rowCount();
}

// ════════════════════════════════════════════════════════════
// 1. TRANSACTIONS — pending manager approval
// ════════════════════════════════════════════════════════════

// Merchandise transactions pending approval
try {
    $s = $sw ? "AND mt.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT mt.id, mt.transaction_id, mt.status, mt.created_at,
                u.name AS staff_name, mt.total_amount
         FROM merchandise_transactions mt
         LEFT JOIN users u ON u.user_id = mt.staff_id
         WHERE mt.status IN ('Pending Approval','pending_approval','Pending','pending')
           AND mt.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s}
         ORDER BY mt.created_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $txn  = $r['transaction_id'] ?? ('#' . $r['id']);
        $staff = $r['staff_name'] ?? 'Staff';
        $amt  = $r['total_amount'] ? '₱' . number_format($r['total_amount'], 2) : '';
        $key  = 'mgr_txn_approval_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'warning', 'transaction', 'medium',
            "Transaction {$txn} Pending Your Approval",
            "Transaction {$txn}{$amt} submitted by {$staff} is pending your approval.",
            $key, 'transactions.php'
        );
    }
} catch (Exception $e) {}

// Fuel transactions pending approval
try {
    $s = $sw ? "AND ft.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT ft.id, ft.status, ft.fuel_type, ft.transaction_date,
                u.name AS staff_name, ft.liters_sold
         FROM fuel_transactions ft
         LEFT JOIN users u ON u.user_id = ft.staff_id
         WHERE ft.status IN ('Pending Approval','pending_approval','Pending','pending')
           AND ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s}
         ORDER BY ft.transaction_date DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff = $r['staff_name'] ?? 'Staff';
        $liters = $r['liters_sold'] ? number_format($r['liters_sold'], 2) . 'L' : '';
        $key  = 'mgr_fuel_txn_approval_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'warning', 'transaction', 'medium',
            "Fuel Transaction #{$r['id']} Pending Approval",
            "Fuel Transaction #{$r['id']} ({$r['fuel_type']} {$liters}) by {$staff} pending your approval.",
            $key, 'manager_fuel_management_complete.php#fuel-transactions'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 2. JOB ORDERS — awaiting validation, completed by staff
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND jo.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT jo.id, jo.job_order_id, jo.status, jo.validation_status,
                jo.customer_name, jo.service_type, jo.updated_at,
                u.name AS staff_name
         FROM job_orders jo
         LEFT JOIN users u ON u.user_id = jo.created_by
         WHERE jo.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s}
         ORDER BY jo.updated_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $jo_num = $r['job_order_id'] ?? ('#' . $r['id']);
        $status = $r['status'] ?? '';
        $val    = $r['validation_status'] ?? '';
        $staff  = $r['staff_name'] ?? 'Staff';

        // Awaiting validation
        if (in_array(strtolower($val), ['pending validation', 'pending'])) {
            $key = 'mgr_jo_validation_' . $r['id'];
            $generated += mgr_push($pdo, $user_id, 'warning', 'job_order', 'high',
                "Job Order {$jo_num} Awaiting Validation",
                "Job Order {$jo_num} ({$r['service_type']}) for {$r['customer_name']} is awaiting your validation.",
                $key, 'manager_job_orders.php'
            );
        }
        // Completed by staff
        elseif (in_array(strtolower($status), ['completed', 'done', 'finished'])) {
            $key = 'mgr_jo_completed_' . $r['id'] . '_' . date('Ymd');
            $generated += mgr_push($pdo, $user_id, 'success', 'job_order', 'low',
                "Job Order {$jo_num} Completed",
                "Staff {$staff} completed Job Order {$jo_num} ({$r['service_type']}) for {$r['customer_name']}.",
                $key, 'manager_job_orders.php'
            );
        }
        // Cancelled/rejected
        elseif (in_array(strtolower($status), ['cancelled', 'rejected', 'canceled'])) {
            $key = 'mgr_jo_cancelled_' . $r['id'];
            $generated += mgr_push($pdo, $user_id, 'error', 'job_order', 'medium',
                "Job Order {$jo_num} {$status}",
                "Job Order {$jo_num} for {$r['customer_name']} was {$status}.",
                $key, 'manager_job_orders.php'
            );
        }
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 3. FUEL MANAGEMENT — low tank levels, refill requests, invalid readings
// ════════════════════════════════════════════════════════════

// Low fuel inventory
try {
    $s = $sw ? "AND fi.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity,
                fi.station_id
         FROM fuel_inventory fi
         WHERE fi.current_level <= (fi.capacity * 0.20)
           {$s}
         ORDER BY (fi.current_level / fi.capacity) ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $pct  = $r['capacity'] > 0 ? round(($r['current_level'] / $r['capacity']) * 100) : 0;
        $key  = 'mgr_fuel_low_' . $r['id'] . '_' . date('Ymd');
        $sev  = $pct <= 10 ? 'critical' : 'high';
        $type = $pct <= 10 ? 'error' : 'warning';
        $generated += mgr_push($pdo, $user_id, $type, 'fuel_management', $sev,
            "Low Stock Alert: {$r['fuel_type']}",
            "Low stock alert: {$r['fuel_type']} at {$pct}% capacity ({$r['current_level']}L remaining).",
            $key, 'manager_fuel_management_complete.php'
        );
    }
} catch (Exception $e) {}

// Invalid meter readings (negative variance)
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, pump_number, computed_liters, reading_date
         FROM fuel_daily_readings
         WHERE reading_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
           AND computed_liters < 0
           {$s}
         ORDER BY reading_date DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $pump = $r['pump_number'] ?? $r['id'];
        $key  = 'mgr_fuel_invalid_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'error', 'fuel_management', 'high',
            "Invalid Meter Input — Pump #{$pump}",
            "Fuel Pump #{$pump} has a negative variance ({$r['computed_liters']}L) on {$r['reading_date']}. Verify reading.",
            $key, 'manager_fuel_management_complete.php'
        );
    }
} catch (Exception $e) {}

// Refill requests pending
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, pump_id, created_at
         FROM fuel_refill_requests
         WHERE status IN ('pending','Pending')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s}
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ts  = date('M d, Y H:i', strtotime($r['created_at']));
        $key = 'mgr_fuel_refill_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'warning', 'fuel_management', 'medium',
            "Fuel Pump #{$r['pump_id']} Refill Request Pending",
            "Fuel Pump #{$r['pump_id']} refill request pending since {$ts}.",
            $key, 'manager_fuel_management_complete.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 4. INVENTORY — threshold crossed, encoding errors
// ════════════════════════════════════════════════════════════
try {
    if ($sw) {
        $stmt = $pdo->prepare(
            "SELECT ip.id, ip.product_name, ip.sku, si.stock_level,
                    COALESCE(ip.reorder_level, 10) AS reorder_level
             FROM station_inventory si
             INNER JOIN inventory_products ip ON ip.id = si.product_id
             WHERE si.station_id = ?
               AND si.stock_level <= COALESCE(ip.reorder_level, 10)
               AND ip.category NOT IN ('Fuel')
             ORDER BY si.stock_level ASC LIMIT 15"
        );
        $stmt->execute([$sw]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query(
            "SELECT id, product_name, sku, stock AS stock_level,
                    COALESCE(reorder_level, 10) AS reorder_level
             FROM inventory_products
             WHERE stock <= COALESCE(reorder_level, 10)
               AND category NOT IN ('Fuel')
             ORDER BY stock ASC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $r) {
        $stock = (int)($r['stock_level'] ?? 0);
        $code  = $r['sku'] ?? ('ID-' . $r['id']);
        $sev   = $stock <= 0 ? 'critical' : ($stock <= 5 ? 'high' : 'medium');
        $type  = $stock <= 0 ? 'error' : 'warning';
        $label = $stock <= 0 ? 'Out of stock' : "Low stock ({$stock} remaining)";
        $key   = 'mgr_inv_low_' . $r['id'] . '_' . date('Ymd');
        $generated += mgr_push($pdo, $user_id, $type, 'inventory', $sev,
            "Inventory Threshold Crossed: {$r['product_name']}",
            "Inventory threshold crossed: {$r['product_name']} ({$code}) — {$label}.",
            $key, 'manager_inventory_merchandise.php'
        );
    }
} catch (Exception $e) {}

// Encoding errors flagged by staff (stock_requests with issues)
try {
    $s = $sw ? "AND sr.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT sr.id, sr.product_name, sr.status, sr.created_at,
                u.name AS staff_name
         FROM stock_requests sr
         LEFT JOIN users u ON u.user_id = sr.staff_id
         WHERE sr.status IN ('flagged','error','Error','Flagged','rejected','Rejected')
           AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY sr.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff = $r['staff_name'] ?? 'Staff';
        $key   = 'mgr_inv_error_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'error', 'inventory', 'medium',
            "Encoding Error Flagged: {$r['product_name']}",
            "Encoding error flagged by {$staff} for {$r['product_name']} (Request #{$r['id']}).",
            $key, 'manager_inventory_stock_requests.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 5. CUSTOMERS — pending ID validation, invalid upload format
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, name, status, id_picture, created_at
         FROM customers
         WHERE status IN ('pending','Pending','pending_validation','Pending Validation')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY created_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'mgr_cust_pending_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'info', 'customer', 'low',
            "Customer Pending Validation",
            "Customer {$r['name']} uploaded ID — pending validation.",
            $key, 'manager_customers.php?section=validation'
        );
    }
} catch (Exception $e) {}

// Customers with invalid picture format
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, name, id_picture, created_at
         FROM customers
         WHERE id_picture IS NOT NULL
           AND id_picture != ''
           AND id_picture NOT REGEXP '\\.(jpg|jpeg|png|gif|webp)$'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'mgr_cust_invalid_pic_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'warning', 'customer', 'medium',
            "Invalid Upload Format",
            "Customer {$r['name']}'s picture has an invalid format. Please review.",
            $key, 'manager_customers.php?section=validation'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 6. DELIVERIES — en route, delayed, awaiting manager action
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND do2.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT do2.id, do2.status, do2.supplier, do2.delivery_date,
                do2.delivery_type, do2.updated_at,
                u.name AS staff_name
         FROM deliveries_oversight do2
         LEFT JOIN users u ON u.user_id = do2.encoded_by
         WHERE do2.updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
           {$s}
         ORDER BY do2.updated_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $status = $r['status'] ?? '';
        $dt     = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';
        $staff  = $r['staff_name'] ?? 'Staff';

        $type = 'info'; $sev = 'low'; $msg = '';

        if (in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation'])) {
            $type = 'warning'; $sev = 'high';
            $msg  = "Delivery #{$r['id']} from {$r['supplier']} is awaiting your action ({$status}).";
            $key  = 'mgr_del_action_' . $r['id'] . '_' . md5($status);
            $generated += mgr_push($pdo, $user_id, $type, 'delivery', $sev,
                "Delivery #{$r['id']} Awaiting Manager Action",
                $msg, $key, 'manager_deliveries_management.php'
            );
        } elseif (in_array($status, ['En Route', 'In Transit', 'Dispatched'])) {
            $key = 'mgr_del_enroute_' . $r['id'];
            $generated += mgr_push($pdo, $user_id, 'info', 'delivery', 'low',
                "Delivery #{$r['id']} En Route",
                "Delivery #{$r['id']} from {$r['supplier']} is en route. Expected: {$dt}.",
                $key, 'manager_deliveries_management.php'
            );
        } elseif (in_array($status, ['Discrepancy', 'Flagged', 'Delayed'])) {
            $key = 'mgr_del_issue_' . $r['id'] . '_' . md5($status);
            $generated += mgr_push($pdo, $user_id, 'error', 'delivery', 'high',
                "Delivery #{$r['id']} — {$status}",
                "Delivery #{$r['id']} from {$r['supplier']} is {$status} — awaiting manager action.",
                $key, 'manager_deliveries_management.php'
            );
        }
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 7. CALENDAR — shift conflicts, new schedule entries
// ════════════════════════════════════════════════════════════

// Today's events for this station
try {
    $s = $sw ? "AND (ce.station_id = {$sw} OR ce.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT ce.id, ce.title, ce.start_time, ce.end_time,
                ce.event_type, u.name AS assigned_name
         FROM calendar_events ce
         LEFT JOIN users u ON u.user_id = ce.user_id
         WHERE DATE(ce.start_time) = CURDATE()
           {$s}
         ORDER BY ce.start_time ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $start = date('h:i A', strtotime($r['start_time']));
        $end   = $r['end_time'] ? date('h:i A', strtotime($r['end_time'])) : 'TBD';
        $title = $r['title'] ?: ucfirst($r['event_type'] ?? 'Event');
        $who   = $r['assigned_name'] ? " — {$r['assigned_name']}" : '';
        $key   = 'mgr_cal_today_' . $r['id'] . '_' . date('Ymd');
        $generated += mgr_push($pdo, $user_id, 'info', 'calendar', 'low',
            "Schedule: {$title}",
            "New schedule entry: {$title}{$who} at {$start} – {$end} today.",
            $key, 'manager_calendar.php'
        );
    }
} catch (Exception $e) {}

// Shift conflicts — same user booked twice in overlapping time
try {
    $s = $sw ? "AND (ce1.station_id = {$sw} OR ce1.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT ce1.id AS id1, ce2.id AS id2,
                ce1.user_id, u.name AS staff_name,
                ce1.start_time, ce1.end_time,
                ce2.start_time AS start2, ce2.end_time AS end2
         FROM calendar_events ce1
         INNER JOIN calendar_events ce2
                 ON ce1.user_id = ce2.user_id
                AND ce1.id < ce2.id
                AND ce1.start_time < ce2.end_time
                AND ce2.start_time < ce1.end_time
         LEFT JOIN users u ON u.user_id = ce1.user_id
         WHERE DATE(ce1.start_time) >= CURDATE()
           AND DATE(ce1.start_time) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           {$s}
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff = $r['staff_name'] ?? 'Staff';
        $t1    = date('h:i A', strtotime($r['start_time']));
        $t2    = date('h:i A', strtotime($r['start2']));
        $key   = 'mgr_cal_conflict_' . $r['id1'] . '_' . $r['id2'];
        $generated += mgr_push($pdo, $user_id, 'error', 'calendar', 'high',
            "Shift Conflict: {$staff}",
            "Shift conflict detected: {$staff} is double-booked at {$t1} and {$t2}.",
            $key, 'manager_calendar.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 8. REPORTS — weekly/monthly reports ready
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND (al.station_id = {$sw} OR al.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Weekly%Report%'
             OR al.action LIKE '%Monthly%Report%'
             OR al.action LIKE '%Report%Generated%'
             OR al.action LIKE '%Sales%Report%'
             OR al.action LIKE '%Fuel%Report%'
             OR al.details LIKE '%weekly%report%'
             OR al.details LIKE '%monthly%report%'
             OR al.details LIKE '%report%ready%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY al.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ts  = date('M d, Y h:i A', strtotime($r['created_at']));
        $key = 'mgr_report_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'success', 'report', 'low',
            $r['action'],
            mb_strimwidth($r['details'] ?? $r['action'], 0, 120, '…') . " — ready at {$ts}.",
            $key, 'manager_reports.php'
        );
    }
} catch (Exception $e) {}

// Daily transaction summary
try {
    $s = $sw ? "AND (al.station_id = {$sw} OR al.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Daily%Summary%'
             OR al.details LIKE '%daily summary%'
             OR al.details LIKE '%daily transaction%')
           AND DATE(al.created_at) = CURDATE()
           {$s}
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ts  = date('h:i A', strtotime($r['created_at']));
        $key = 'mgr_daily_summary_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'success', 'report', 'low',
            "Daily Transaction Summary Ready",
            "Daily transaction summary ready for viewing at {$ts}.",
            $key, 'manager_reports.php'
        );
    }
} catch (Exception $e) {}

// ── Cleanup old read notifications (>14 days) ────────────────
try {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ? AND status = 'read'
           AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $stmt->execute([$user_id]);
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
