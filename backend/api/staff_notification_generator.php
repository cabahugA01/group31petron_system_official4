<?php
/**
 * Staff Notification Generator
 * backend/api/staff_notification_generator.php
 *
 * Scans real operational tables and inserts dynamic notifications into the
 * notifications table for the currently logged-in staff/manager/admin user.
 *
 * Called via AJAX from the header on every page load for non-superadmin roles.
 * Uses source_key to prevent duplicate notifications.
 *
 * Notification Sources:
 *  1. Transactions  — merchandise_transactions / fuel_transactions (status changes)
 *  2. Job Orders    — job_orders (new assignment, status change)
 *  3. Fuel Mgmt     — fuel_transactions / fuel_daily_readings (refill requests, invalid input)
 *  4. Inventory     — inventory_products / station_inventory (low stock alerts)
 *  5. Customers     — customers (pending validation uploads)
 *  6. Deliveries    — deliveries_oversight (new assignment, status update)
 *  7. Calendar      — calendar_events (upcoming shifts/events today)
 *  8. Reports       — activity_logs (daily summary ready)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

// Only for operational roles (not superadmin — they have their own generator)
$allowed_roles = ['staff', 'manager', 'admin'];
if (!in_array($role, $allowed_roles)) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

$station_id = (int)($me['station_id'] ?? 0);

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
} catch (Exception $e) { /* already exists */ }

$generated = 0;

/**
 * Insert a notification for the current user.
 * source_key prevents duplicates — same event is never inserted twice.
 */
function push_notif(
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
// 1. TRANSACTIONS
//    Source: merchandise_transactions, fuel_transactions
//    Trigger: Recent failed/pending transactions for this user's station
// ════════════════════════════════════════════════════════════
try {
    // Merchandise transactions — failed or pending in last 24h
    $where_station = $station_id ? 'AND mt.station_id = ' . $station_id : '';
    $rows = $pdo->query(
        "SELECT mt.id, mt.transaction_id, mt.status, mt.created_at,
                u.name AS staff_name
         FROM merchandise_transactions mt
         LEFT JOIN users u ON u.id = mt.staff_id
         WHERE mt.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
           AND mt.status IN ('failed','pending','Pending','Failed')
           $where_station
         ORDER BY mt.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $txn_id  = $r['transaction_id'] ?? ('#' . $r['id']);
        $status  = ucfirst(strtolower($r['status']));
        $ts      = date('M d, Y H:i', strtotime($r['created_at']));
        $key     = 'txn_merch_' . $r['id'];
        $type    = strtolower($r['status']) === 'failed' ? 'error' : 'warning';
        $sev     = strtolower($r['status']) === 'failed' ? 'high' : 'medium';
        $generated += push_notif(
            $pdo, $user_id, $type, 'transaction',
            $sev,
            "Transaction {$txn_id} {$status}",
            "Transaction {$txn_id} {$status} at {$ts}.",
            $key,
            'staff_transactions_hub.php'
        );
    }
} catch (Exception $e) {}

// Fuel transactions — failed or pending
try {
    $where_station = $station_id ? 'AND ft.station_id = ' . $station_id : '';
    $rows = $pdo->query(
        "SELECT ft.id, ft.status, ft.transaction_date, ft.fuel_type
         FROM fuel_transactions ft
         WHERE ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
           AND ft.status IN ('failed','pending','Pending','Failed')
           $where_station
         ORDER BY ft.transaction_date DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $status = ucfirst(strtolower($r['status']));
        $ts     = date('M d, Y H:i', strtotime($r['transaction_date']));
        $key    = 'txn_fuel_' . $r['id'];
        $type   = strtolower($r['status']) === 'failed' ? 'error' : 'warning';
        $sev    = strtolower($r['status']) === 'failed' ? 'high' : 'medium';
        $generated += push_notif(
            $pdo, $user_id, $type, 'transaction',
            $sev,
            "Fuel Transaction #{$r['id']} {$status}",
            "Fuel Transaction #{$r['id']} ({$r['fuel_type']}) {$status} at {$ts}.",
            $key,
            'staff_transactions_hub.php?section=fuel'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 2. JOB ORDERS
//    Source: job_orders
//    Trigger: New assignment to this user, or status change in last 24h
// ════════════════════════════════════════════════════════════
try {
    $where_station = $station_id ? 'AND jo.station_id = ' . $station_id : '';
    // For staff: jobs they created or are assigned to
    // For manager/admin: all jobs in their station
    $user_filter = in_array($role, ['staff'])
        ? "AND (jo.created_by = {$user_id} OR jo.assigned_to = {$user_id})"
        : '';

    $rows = $pdo->query(
        "SELECT jo.id, jo.job_order_id, jo.status, jo.validation_status,
                jo.customer_name, jo.service_type, jo.created_at, jo.updated_at
         FROM job_orders jo
         WHERE jo.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           $where_station
           $user_filter
         ORDER BY jo.created_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $jo_num  = $r['job_order_id'] ?? ('#' . $r['id']);
        $status  = $r['status'] ?? 'Unknown';
        $val_st  = $r['validation_status'] ?? '';
        $display = $val_st && $val_st !== $status ? "{$status} / {$val_st}" : $status;

        // Determine notification type by status
        $type = 'info';
        $sev  = 'low';
        if (in_array(strtolower($status), ['pending validation', 'pending'])) {
            $type = 'warning'; $sev = 'medium';
        } elseif (in_array(strtolower($status), ['completed', 'paid', 'released'])) {
            $type = 'success'; $sev = 'low';
        } elseif (in_array(strtolower($status), ['cancelled', 'rejected'])) {
            $type = 'error'; $sev = 'high';
        }

        $key = 'jo_status_' . $r['id'] . '_' . md5($display);
        $generated += push_notif(
            $pdo, $user_id, $type, 'job_order',
            $sev,
            "Job Order {$jo_num} — {$display}",
            "Job Order {$jo_num} ({$r['service_type']}) for {$r['customer_name']} is now {$display}.",
            $key,
            'joborder.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 3. FUEL MANAGEMENT
//    Source: fuel_daily_readings / fuel_transactions
//    Trigger: Refill requests pending, invalid meter input (negative variance)
// ════════════════════════════════════════════════════════════
try {
    $where_station = $station_id ? 'AND station_id = ' . $station_id : '';
    $rows = $pdo->query(
        "SELECT id, pump_number, computed_liters, reading_date, station_id
         FROM fuel_daily_readings
         WHERE reading_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
           AND computed_liters < 0
           $where_station
         ORDER BY reading_date DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $pump = $r['pump_number'] ?? $r['id'];
        $key  = 'fuel_invalid_reading_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'error', 'fuel_management',
            'high',
            "Invalid Meter Input — Pump #{$pump}",
            "Fuel Pump #{$pump} has a negative variance ({$r['computed_liters']} L) on {$r['reading_date']}. Please verify meter reading.",
            $key,
            'fuel_readings_encoding.php'
        );
    }
} catch (Exception $e) {}

// Fuel refill requests pending
try {
    $where_station = $station_id ? 'AND station_id = ' . $station_id : '';
    $rows = $pdo->query(
        "SELECT id, pump_id, status, created_at
         FROM fuel_refill_requests
         WHERE status IN ('pending','Pending')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           $where_station
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'fuel_refill_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'warning', 'fuel_management',
            'medium',
            "Fuel Pump #{$r['pump_id']} Refill Request Pending",
            "Fuel Pump #{$r['pump_id']} refill request pending since " . date('M d, Y H:i', strtotime($r['created_at'])) . ".",
            $key,
            'fuel_readings_encoding.php'
        );
    }
} catch (Exception $e) { /* table may not exist */ }

// ════════════════════════════════════════════════════════════
// 4. INVENTORY
//    Source: inventory_products / station_inventory
//    Trigger: Stock at or below reorder level
// ════════════════════════════════════════════════════════════
try {
    if ($station_id) {
        // Station-specific inventory
        $rows = $pdo->prepare(
            "SELECT ip.id, ip.product_name, ip.sku, si.stock_level,
                    COALESCE(ip.reorder_level, 10) AS reorder_level
             FROM station_inventory si
             INNER JOIN inventory_products ip ON ip.id = si.product_id
             WHERE si.station_id = ?
               AND si.stock_level <= COALESCE(ip.reorder_level, 10)
               AND ip.category NOT IN ('Fuel')
             ORDER BY si.stock_level ASC LIMIT 15"
        );
        $rows->execute([$station_id]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
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
        $item_name = $r['product_name'] ?? 'Unknown Item';
        $item_code = $r['sku'] ?? ('ID-' . $r['id']);
        $stock     = (int)($r['stock_level'] ?? 0);
        $key       = 'low_stock_' . $r['id'] . '_' . date('Ymd');
        $sev       = $stock <= 0 ? 'critical' : ($stock <= 5 ? 'high' : 'medium');
        $type      = $stock <= 0 ? 'error' : 'warning';
        $label     = $stock <= 0 ? 'Out of stock' : "Low stock ({$stock} remaining)";
        $generated += push_notif(
            $pdo, $user_id, $type, 'inventory',
            $sev,
            "Low Stock Alert: {$item_name}",
            "{$label}: {$item_name} ({$item_code}).",
            $key,
            'staff_inventory.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 5. CUSTOMERS
//    Source: customers table
//    Trigger: Customers with pending validation status
// ════════════════════════════════════════════════════════════
try {
    $where_station = $station_id ? 'AND station_id = ' . $station_id : '';
    $rows = $pdo->query(
        "SELECT id, name, status, created_at
         FROM customers
         WHERE status IN ('pending','Pending','pending_validation','Pending Validation')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           $where_station
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'customer_pending_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'info', 'customer',
            'low',
            "Customer Pending Validation",
            "Customer {$r['name']} uploaded ID — pending validation.",
            $key,
            'customers.php?section=validation'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 6. DELIVERIES MANAGEMENT
//    Source: deliveries_oversight
//    Trigger: New assignment, status update in last 48h
// ════════════════════════════════════════════════════════════
try {
    $where_station = $station_id ? 'AND do2.station_id = ' . $station_id : '';
    $user_filter   = $role === 'staff'
        ? "AND (do2.encoded_by = {$user_id} OR do2.assigned_to = {$user_id})"
        : '';

    $rows = $pdo->query(
        "SELECT do2.id, do2.status, do2.supplier, do2.delivery_date,
                do2.delivery_type, do2.updated_at
         FROM deliveries_oversight do2
         WHERE do2.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           $where_station
           $user_filter
         ORDER BY do2.updated_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $status  = $r['status'] ?? 'Unknown';
        $type    = 'info';
        $sev     = 'low';
        if (in_array($status, ['Pending Validation', 'Pending Manager Approval', 'Pending Manager Confirmation'])) {
            $type = 'warning'; $sev = 'medium';
        } elseif (in_array($status, ['Discrepancy', 'Flagged'])) {
            $type = 'error'; $sev = 'high';
        } elseif (in_array($status, ['Confirmed', 'Validated'])) {
            $type = 'success'; $sev = 'low';
        }

        $key = 'delivery_' . $r['id'] . '_' . md5($status);
        $generated += push_notif(
            $pdo, $user_id, $type, 'delivery',
            $sev,
            "Delivery #{$r['id']} — {$status}",
            "Delivery #{$r['id']} from {$r['supplier']} status updated to {$status}.",
            $key,
            'staff_record_delivery.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 7. CALENDAR / SCHEDULE
//    Source: calendar_events
//    Trigger: Events/shifts starting today for this user
// ════════════════════════════════════════════════════════════
try {
    $where_station = $station_id ? 'AND (ce.station_id = ' . $station_id . ' OR ce.station_id IS NULL)' : '';
    $user_filter   = "AND (ce.user_id = {$user_id} OR ce.user_id IS NULL OR ce.assigned_to = {$user_id})";

    $rows = $pdo->query(
        "SELECT ce.id, ce.title, ce.start_time, ce.end_time, ce.event_type,
                ce.description
         FROM calendar_events ce
         WHERE DATE(ce.start_time) = CURDATE()
           $where_station
           $user_filter
         ORDER BY ce.start_time ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $start = date('h:i A', strtotime($r['start_time']));
        $end   = $r['end_time'] ? date('h:i A', strtotime($r['end_time'])) : 'TBD';
        $title = $r['title'] ?? ($r['event_type'] ?? 'Event');
        $key   = 'calendar_today_' . $r['id'] . '_' . date('Ymd');
        $generated += push_notif(
            $pdo, $user_id, 'info', 'calendar',
            'low',
            "Shift Reminder: {$title}",
            "Shift reminder: {$start} – {$end} today. {$r['description']}",
            $key,
            'staff_calendar.php'
        );
    }
} catch (Exception $e) { /* calendar_events may not exist */ }

// ════════════════════════════════════════════════════════════
// 8. REPORTS
//    Source: activity_logs
//    Trigger: Daily transaction summary generated today
// ════════════════════════════════════════════════════════════
try {
    $where_station = $station_id ? 'AND (al.station_id = ' . $station_id . ' OR al.station_id IS NULL)' : '';
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Daily%Summary%' OR al.action LIKE '%Report%Generated%'
                OR al.action LIKE '%report_generated%' OR al.details LIKE '%daily summary%')
           AND DATE(al.created_at) = CURDATE()
           $where_station
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ts  = date('h:i A', strtotime($r['created_at']));
        $key = 'report_daily_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'success', 'report',
            'low',
            "Daily Transaction Summary Ready",
            "Daily transaction summary ready at {$ts}.",
            $key,
            'staff_reports.php'
        );
    }
} catch (Exception $e) {}

// ── Clean up old read notifications for this user (>14 days) ─
try {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ? AND status = 'read'
           AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $stmt->execute([$user_id]);
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
