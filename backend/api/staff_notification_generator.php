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
 * Notification Sources (schema-verified):
 *  1. Fuel Transactions      — fuel_transactions (Pending Validation)
 *  2. Job Orders             — job_orders (pending/in-progress/completed)
 *  3. Fuel Management        — fuel_inventory (low tanks ≤ 20%)
 *  4. Inventory              — station_inventory + inventory_products (low stock)
 *  5. Customers              — customers (pending validation)
 *  6. Deliveries             — deliveries_oversight (status updates 48h)
 *  7. Reports                — activity_logs (daily summaries)
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
$sw = $station_id ? $station_id : 0;

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
// 1. FUEL TRANSACTIONS — Pending Validation (last 48h)
//    Column verified: status, transaction_id, fuel_type, station_id
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND ft.station_id = {$sw}" : '';
    // Staff: own transactions; manager/admin: all station transactions
    $u = ($role === 'staff') ? "AND ft.staff_id = {$user_id}" : '';

    $rows = $pdo->query(
        "SELECT ft.id, ft.transaction_id, ft.status, ft.fuel_type,
                ft.transaction_date, ft.liters_sold, u.name AS staff_name
         FROM fuel_transactions ft
         LEFT JOIN users u ON u.id = ft.staff_id
         WHERE ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           AND ft.status IN ('Pending Validation','pending_validation','Pending','pending','Failed','failed')
           {$s} {$u}
         ORDER BY ft.transaction_date DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $txn    = $r['transaction_id'] ?? ('#' . $r['id']);
        $status = $r['status'];
        $liters = $r['liters_sold'] ? number_format($r['liters_sold'], 2) . 'L' : '';
        $ts     = date('M d, H:i', strtotime($r['transaction_date']));
        $type   = in_array(strtolower($status), ['failed']) ? 'error' : 'warning';
        $sev    = in_array(strtolower($status), ['failed']) ? 'high' : 'medium';
        $key    = 'txn_fuel_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, $type, 'transaction',
            $sev,
            "Fuel Transaction #{$r['id']} — {$status}",
            "Fuel Transaction #{$r['id']} ({$r['fuel_type']} {$liters}) is {$status} at {$ts}.",
            $key,
            'staff_transactions_hub.php?section=fuel'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 2. JOB ORDERS — recent status changes (48h)
//    Column verified: job_order_id, status, validation_status,
//                     customer_name, service_type, created_by, user_id
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND jo.station_id = {$sw}" : '';
    // For staff: jobs they created or are assigned to
    $u = ($role === 'staff')
        ? "AND (jo.created_by = {$user_id} OR jo.user_id = {$user_id})"
        : '';

    $rows = $pdo->query(
        "SELECT jo.id, jo.job_order_id, jo.status, jo.validation_status,
                jo.customer_name, jo.service_type, jo.created_at, jo.updated_at
         FROM job_orders jo
         WHERE jo.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s} {$u}
         ORDER BY jo.created_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $jo_num  = $r['job_order_id'] ?? ('#' . $r['id']);
        $status  = $r['status'] ?? 'Unknown';
        $val_st  = $r['validation_status'] ?? '';
        $display = $val_st && $val_st !== $status ? "{$status} / {$val_st}" : $status;
        $cust    = $r['customer_name'] ?? 'Customer';
        $svc     = $r['service_type'] ?? 'Service';

        $type = 'info'; $sev = 'low';
        if (in_array(strtolower($status), ['pending validation', 'pending'])) {
            $type = 'warning'; $sev = 'medium';
        } elseif (in_array(strtolower($status), ['completed', 'done', 'paid', 'released'])) {
            $type = 'success'; $sev = 'low';
        } elseif (in_array(strtolower($status), ['cancelled', 'rejected', 'canceled'])) {
            $type = 'error'; $sev = 'high';
        }

        $key = 'jo_status_' . $r['id'] . '_' . md5($display);
        $generated += push_notif(
            $pdo, $user_id, $type, 'job_order',
            $sev,
            "Job Order {$jo_num} — {$display}",
            "Job Order {$jo_num} ({$svc}) for {$cust} is now {$display}.",
            $key,
            'staff_transactions_hub.php?section=merchandise&active_tab=tracker'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 3. FUEL MANAGEMENT — low fuel inventory (≤ 20%)
//    Column verified: fuel_type, current_level, capacity, station_id
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND fi.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity, fi.station_id
         FROM fuel_inventory fi
         WHERE fi.current_level >= 0
           AND fi.capacity > 0
           AND fi.current_level <= (fi.capacity * 0.20)
           {$s}
         ORDER BY (fi.current_level / fi.capacity) ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $pct  = $r['capacity'] > 0 ? round(($r['current_level'] / $r['capacity']) * 100) : 0;
        $sev  = $pct <= 5 ? 'critical' : ($pct <= 10 ? 'high' : 'medium');
        $type = $pct <= 5 ? 'error' : 'warning';
        $key  = 'fuel_low_' . $r['id'] . '_' . date('Ymd');
        $generated += push_notif(
            $pdo, $user_id, $type, 'fuel_management',
            $sev,
            "Low Fuel Alert: {$r['fuel_type']}",
            "{$r['fuel_type']} is at {$pct}% capacity ({$r['current_level']}L remaining). Refill needed.",
            $key,
            'staff_transactions_hub.php?section=fuel'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 4. INVENTORY — low stock alerts
//    Column verified: si.reorder_level (station_inventory), ip.min_stock (inventory_products)
// ════════════════════════════════════════════════════════════
try {
    if ($sw) {
        $stmt = $pdo->prepare(
            "SELECT ip.id, ip.product_name, ip.sku, si.stock_level,
                    COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level
             FROM station_inventory si
             INNER JOIN inventory_products ip ON ip.id = si.product_id
             WHERE si.station_id = ?
               AND si.stock_level >= 0
               AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10)
               AND LOWER(ip.category) NOT IN ('fuel', 'fuels')
             ORDER BY si.stock_level ASC LIMIT 15"
        );
        $stmt->execute([$sw]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query(
            "SELECT id, product_name, sku, COALESCE(stock_quantity, stock, 0) AS stock_level,
                    COALESCE(min_stock, 10) AS reorder_level
             FROM inventory_products
             WHERE COALESCE(stock_quantity, stock, 0) <= COALESCE(min_stock, 10)
               AND LOWER(category) NOT IN ('fuel', 'fuels')
             ORDER BY stock_level ASC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $r) {
        $stock = (int)($r['stock_level'] ?? 0);
        $code  = $r['sku'] ?? ('ID-' . $r['id']);
        $sev   = $stock <= 0 ? 'critical' : ($stock <= 5 ? 'high' : 'medium');
        $type  = $stock <= 0 ? 'error' : 'warning';
        $label = $stock <= 0 ? 'Out of stock' : "Low stock ({$stock} remaining)";
        $key   = 'low_stock_' . $r['id'] . '_' . date('Ymd');
        $generated += push_notif(
            $pdo, $user_id, $type, 'inventory',
            $sev,
            "Low Stock Alert: {$r['product_name']}",
            "{$label}: {$r['product_name']} ({$code}).",
            $key,
            'staff_inventory.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 5. CUSTOMERS — pending validation (7d)
//    Column verified: status (values: 'active', 'pending', etc.)
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, name, status, created_at
         FROM customers
         WHERE status IN ('pending','Pending','pending_validation','Pending Validation')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $cust = $r['name'] ?? ('Customer #' . $r['id']);
        $key  = 'customer_pending_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'info', 'customer',
            'low',
            "Customer Pending Validation",
            "Customer {$cust} uploaded ID — pending validation.",
            $key,
            'staff_customer_list.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 6. DELIVERIES — recent status updates (48h)
//    Column verified: status, supplier, delivery_date, updated_at
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND do2.station_id = {$sw}" : '';
    // For staff: only deliveries they encoded
    $u = ($role === 'staff') ? "AND do2.encoded_by = {$user_id}" : '';

    $rows = $pdo->query(
        "SELECT do2.id, do2.status, do2.supplier, do2.delivery_date,
                do2.delivery_type, do2.updated_at
         FROM deliveries_oversight do2
         WHERE do2.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s} {$u}
         ORDER BY do2.updated_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $status   = $r['status'] ?? 'Unknown';
        $supplier = $r['supplier'] ?? 'Supplier';
        $dt       = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';

        $type = 'info'; $sev = 'low';
        if (in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Verification'])) {
            $type = 'warning'; $sev = 'medium';
        } elseif (in_array($status, ['Discrepancy', 'Flagged', 'Delayed'])) {
            $type = 'error'; $sev = 'high';
        } elseif (in_array($status, ['Confirmed', 'Validated', 'Stock-In Complete'])) {
            $type = 'success'; $sev = 'low';
        } elseif (in_array($status, ['En Route', 'In Transit', 'Dispatched', 'Expected Delivery'])) {
            $type = 'info'; $sev = 'low';
        }

        $key = 'delivery_' . $r['id'] . '_' . md5($status);
        $generated += push_notif(
            $pdo, $user_id, $type, 'delivery',
            $sev,
            "Delivery #{$r['id']} — {$status}",
            "Delivery #{$r['id']} from {$supplier} is now {$status}. Expected: {$dt}.",
            $key,
            'staff_record_delivery.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 7. REPORTS — daily transaction summary
//    Source: activity_logs
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND (al.station_id = {$sw} OR al.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Daily%Summary%' OR al.action LIKE '%Report%Generated%'
                OR al.action LIKE '%report_generated%' OR al.details LIKE '%daily summary%')
           AND DATE(al.created_at) = CURDATE()
           {$s}
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

// ── Clean up old read notifications for this user (> 14 days) ─
try {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ? AND status = 'read'
           AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $stmt->execute([$user_id]);
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
