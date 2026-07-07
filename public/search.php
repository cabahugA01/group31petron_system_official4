<?php
/**
 * Unified Search API + Full Results Page
 * public/search.php
 *
 * Role-aware: staff gets station-scoped results; manager gets deeper
 * oversight results including Product Management and approval queues.
 *
 *  1. Transactions   — merchandise_transactions, fuel_transactions
 *  2. Customers      — customers
 *  3. Products/Inv   — inventory_products, station_inventory
 *  4. Job Orders     — job_orders
 *  5. Deliveries     — deliveries_oversight
 *  6. Calendar       — calendar_events
 *  7. Reports        — activity_logs
 *  8. Product Mgmt   — inventory_products pricing (manager only)
 *  9. Fuel Mgmt      — fuel_inventory, fuel_daily_readings (manager only)
 *
 * Supports:
 *  ?q=<query>          — full results page
 *  ?q=<query>&ajax=1   — JSON autocomplete (max 4 per category)
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me          = current_user();
$role        = role_key($me['role'] ?? 'staff');
$user_id     = (int)($me['id'] ?? 0);
$station_id  = (int)(user_station_id() ?? 0);

if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: home.php');
    exit;
}

$query   = trim($_GET['q'] ?? '');
$is_ajax = isset($_GET['ajax']);
$results = [];

// ── Helper: build station WHERE clause ───────────────────────
function station_where(string $alias, int $station_id): string {
    return $station_id ? "AND {$alias}.station_id = {$station_id}" : '';
}

// Log full page search queries
if (!empty($query) && !$is_ajax) {
    log_activity($pdo, $user_id, 'Global Search', "User searched for: {$query}");
}

// ── Icon map per category ─────────────────────────────────────
$ICONS = [
    'Transaction'      => 'fas fa-shopping-cart',
    'Customer'         => 'fas fa-user',
    'Product'          => 'fas fa-box',
    'Job Order'        => 'fas fa-wrench',
    'Delivery'         => 'fas fa-truck',
    'Calendar'         => 'fas fa-calendar-alt',
    'Report'           => 'fas fa-chart-bar',
    'Product Mgmt'     => 'fas fa-tags',
    'Fuel Management'  => 'fas fa-gas-pump',
    'Station'          => 'fas fa-gas-pump',
    'Admin'            => 'fas fa-user-shield',
    'System Log'       => 'fas fa-server',
    'Security'         => 'fas fa-shield-alt',
    'Audit Trail'      => 'fas fa-history',
];

$COLORS = [
    'Transaction'      => '#3b82f6',
    'Customer'         => '#10b981',
    'Product'          => '#f59e0b',
    'Job Order'        => '#8b5cf6',
    'Delivery'         => '#ef4444',
    'Calendar'         => '#06b6d4',
    'Report'           => '#64748b',
    'Product Mgmt'     => '#e11d48',
    'Fuel Management'  => '#f97316',
    'Station'          => '#002F6C',
    'Admin'            => '#7c3aed',
    'System Log'       => '#dc2626',
    'Security'         => '#b91c1c',
    'Audit Trail'      => '#0891b2',
];

$is_manager = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

if (!empty($query)) {
    $like = "%{$query}%";

    // ════════════════════════════════════════════════════════
    // 1. TRANSACTIONS
    //    Fields: transaction_id, date, status, fuel_type, payment_method
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('transactions') || in_array($role, ['superadmin', 'developer'])) {

        // Merchandise transactions
        try {
            $sw = $station_id ? "AND mt.station_id = {$station_id}" : '';
            $stmt = $pdo->prepare(
                "SELECT mt.id, mt.transaction_id, mt.status,
                        mt.created_at, mt.payment_method,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name
                 FROM merchandise_transactions mt
                 LEFT JOIN users u ON u.id = mt.staff_id
                 WHERE (mt.transaction_id LIKE ?
                     OR mt.status LIKE ?
                     OR mt.payment_method LIKE ?
                     OR u.username LIKE ?
                     OR DATE(mt.created_at) LIKE ?)
                   {$sw}
                 ORDER BY mt.created_at DESC LIMIT 10"
            );
            $date_like = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
            $stmt->execute([$like, $like, $like, $like, $date_like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $txn_id = $r['transaction_id'] ?? ('#' . $r['id']);
                $ts     = date('M d, Y H:i', strtotime($r['created_at']));
                
                $txn_link = 'staff_transactions_hub.php?section=merchandise';
                if ($role === 'manager') {
                    $txn_link = 'pending_transactions.php';
                } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $txn_link = 'admin_transactions_oversight.php';
                }
                
                $results[] = [
                    'type'     => 'Transaction',
                    'title'    => "Transaction {$txn_id}",
                    'subtitle' => "Status: {$r['status']} · {$ts}",
                    'meta'     => $r['payment_method'] ?? '',
                    'link'     => $txn_link,
                    'icon'     => $ICONS['Transaction'],
                    'color'    => $COLORS['Transaction'],
                ];
            }
        } catch (Exception $e) {}

        // Fuel transactions
        try {
            $sw = $station_id ? "AND ft.station_id = {$station_id}" : '';
            $stmt = $pdo->prepare(
                "SELECT ft.id, ft.status, ft.fuel_type,
                        ft.transaction_date, ft.shift_period
                 FROM fuel_transactions ft
                 WHERE (ft.fuel_type LIKE ?
                     OR ft.status LIKE ?
                     OR ft.shift_period LIKE ?
                     OR CAST(ft.id AS CHAR) LIKE ?
                     OR DATE(ft.transaction_date) LIKE ?)
                   {$sw}
                 ORDER BY ft.transaction_date DESC LIMIT 10"
            );
            $date_like = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
            $stmt->execute([$like, $like, $like, $like, $date_like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y H:i', strtotime($r['transaction_date']));
                
                $txn_link = 'staff_transactions_hub.php?section=fuel';
                if ($role === 'manager') {
                    $txn_link = 'manager_fuel_transaction_validation.php';
                } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $txn_link = 'admin_fuel_transactions_oversight.php';
                }
                
                $results[] = [
                    'type'     => 'Transaction',
                    'title'    => "Fuel Transaction #{$r['id']} — {$r['fuel_type']}",
                    'subtitle' => "Status: {$r['status']} · {$ts}",
                    'meta'     => $r['shift_period'] ?? '',
                    'link'     => $txn_link,
                    'icon'     => $ICONS['Transaction'],
                    'color'    => $COLORS['Transaction'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 2. CUSTOMERS
    //    Fields: name, customer_id, phone, email, status
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('customers') || in_array($role, ['superadmin', 'developer'])) {
        try {
            $sw = $station_id ? "AND c.station_id = {$station_id}" : '';
            $stmt = $pdo->prepare(
                "SELECT c.id, c.name, c.phone, c.email, c.status,
                        c.customer_code
                 FROM customers c
                 WHERE (c.name LIKE ?
                     OR c.phone LIKE ?
                     OR c.email LIKE ?
                     OR c.customer_code LIKE ?
                     OR c.status LIKE ?)
                   {$sw}
                 ORDER BY c.name ASC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $contact = $r['phone'] ?: ($r['email'] ?: 'No contact');
                $code    = $r['customer_code'] ? " · ID: {$r['customer_code']}" : '';
                
                $cust_link = 'customers.php?edit=' . $r['id'];
                if ($role === 'manager') {
                    $cust_link = 'manager_customers.php?edit=' . $r['id'];
                } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $cust_link = 'admin_customer_management.php?section=list';
                }
                
                $results[] = [
                    'type'     => 'Customer',
                    'title'    => $r['name'],
                    'subtitle' => "{$contact}{$code} · Status: {$r['status']}",
                    'meta'     => $r['status'],
                    'link'     => $cust_link,
                    'icon'     => $ICONS['Customer'],
                    'color'    => $COLORS['Customer'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 3. PRODUCTS / INVENTORY
    //    Fields: product_name, sku, category, stock_level
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('inventory') || in_array($role, ['superadmin', 'developer'])) {
        try {
            if ($station_id) {
                $stmt = $pdo->prepare(
                    "SELECT ip.id, ip.product_name, ip.sku, ip.category,
                            COALESCE(si.stock_level, ip.stock) AS stock_level,
                            ip.unit_price
                     FROM inventory_products ip
                     LEFT JOIN station_inventory si
                            ON si.product_id = ip.id AND si.station_id = ?
                     WHERE (ip.product_name LIKE ?
                         OR ip.sku LIKE ?
                         OR ip.category LIKE ?
                         OR CAST(COALESCE(si.stock_level, ip.stock) AS CHAR) LIKE ?)
                     ORDER BY ip.product_name ASC LIMIT 10"
                );
                $stmt->execute([$station_id, $like, $like, $like, $like]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, product_name, sku, category,
                            stock AS stock_level, unit_price
                     FROM inventory_products
                     WHERE (product_name LIKE ?
                         OR sku LIKE ?
                         OR category LIKE ?
                         OR CAST(stock AS CHAR) LIKE ?)
                     ORDER BY product_name ASC LIMIT 10"
                );
                $stmt->execute([$like, $like, $like, $like]);
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stock  = (int)($r['stock_level'] ?? 0);
                $avail  = $stock > 0 ? "In Stock ({$stock})" : 'Out of Stock';
                
                $inv_link = 'staff_inventory.php';
                if ($role === 'manager') {
                    $inv_link = 'manager_inventory_merchandise.php';
                } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $inv_link = 'admin_inventory_merchandise.php';
                }
                
                $results[] = [
                    'type'     => 'Product',
                    'title'    => $r['product_name'],
                    'subtitle' => "SKU: {$r['sku']} · {$r['category']} · {$avail}",
                    'meta'     => $r['category'],
                    'link'     => $inv_link,
                    'icon'     => $ICONS['Product'],
                    'color'    => $COLORS['Product'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 4. JOB ORDERS
    //    Fields: job_order_id, assigned staff, status, customer_name, service_type
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('job_orders') || in_array($role, ['superadmin', 'developer'])) {
        try {
            $sw = $station_id ? "AND jo.station_id = {$station_id}" : '';
            // Staff only see their own; manager/admin see all
            $uf = $role === 'staff' ? "AND (jo.created_by = {$user_id} OR jo.assigned_to = {$user_id})" : '';
            $stmt = $pdo->prepare(
                "SELECT jo.id, jo.job_order_id, jo.status,
                        jo.customer_name, jo.service_type,
                        jo.created_at, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name
                 FROM job_orders jo
                 LEFT JOIN users u ON u.id = jo.created_by
                 WHERE (jo.job_order_id LIKE ?
                     OR jo.customer_name LIKE ?
                     OR jo.service_type LIKE ?
                     OR jo.status LIKE ?
                     OR u.username LIKE ?)
                   {$sw} {$uf}
                 ORDER BY jo.created_at DESC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $jo_num = $r['job_order_id'] ?? ('#' . $r['id']);
                $jo_link = $is_manager ? 'manager_job_orders.php' : ('staff_transactions_hub.php?section=history&hsearch=' . urlencode($jo_num));
                $results[] = [
                    'type'     => 'Job Order',
                    'title'    => "Job Order {$jo_num}",
                    'subtitle' => "{$r['service_type']} · {$r['customer_name']} · {$r['status']}",
                    'meta'     => $r['status'],
                    'link'     => $jo_link,
                    'icon'     => $ICONS['Job Order'],
                    'color'    => $COLORS['Job Order'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 5. DELIVERIES
    //    Fields: delivery_id, assigned staff/driver, status, supplier
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('deliveries') || in_array($role, ['superadmin', 'developer'])) {
        try {
            $sw = $station_id ? "AND do2.station_id = {$station_id}" : '';
            $uf = $role === 'staff' ? "AND (do2.encoded_by = {$user_id} OR do2.assigned_to = {$user_id})" : '';
            $stmt = $pdo->prepare(
                "SELECT do2.id, do2.status, do2.supplier,
                        do2.delivery_date, do2.delivery_type,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name
                 FROM deliveries_oversight do2
                 LEFT JOIN users u ON u.id = do2.encoded_by
                 WHERE (CAST(do2.id AS CHAR) LIKE ?
                     OR do2.status LIKE ?
                     OR do2.supplier LIKE ?
                     OR do2.delivery_type LIKE ?
                     OR u.username LIKE ?)
                   {$sw} {$uf}
                 ORDER BY do2.delivery_date DESC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dt = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';
                
                $del_link = 'staff_record_delivery.php';
                if ($role === 'manager') {
                    $del_link = 'manager_merchandise_deliveries.php';
                } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $del_link = 'admin_merchandise_deliveries_oversight.php';
                }
                
                $results[] = [
                    'type'     => 'Delivery',
                    'title'    => "Delivery #{$r['id']} — {$r['supplier']}",
                    'subtitle' => "Status: {$r['status']} · {$dt} · {$r['delivery_type']}",
                    'meta'     => $r['status'],
                    'link'     => $del_link,
                    'icon'     => $ICONS['Delivery'],
                    'color'    => $COLORS['Delivery'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 6. CALENDAR / SCHEDULE
    //    Fields: event title, date/time, assigned staff, event_type
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('calendar') || in_array($role, ['superadmin', 'developer'])) {
        try {
            $sw = $station_id ? "AND (ce.station_id = {$station_id} OR ce.station_id IS NULL)" : '';
            $uf = $role === 'staff'
                ? "AND (ce.user_id = {$user_id} OR ce.user_id IS NULL OR ce.assigned_to = {$user_id})"
                : '';
            $stmt = $pdo->prepare(
                "SELECT ce.id, ce.title, ce.start_time, ce.end_time,
                        ce.event_type, ce.description,
                        u.username AS assigned_name
                 FROM calendar_events ce
                 LEFT JOIN users u ON u.id = ce.user_id
                 WHERE (ce.title LIKE ?
                     OR ce.event_type LIKE ?
                     OR ce.description LIKE ?
                     OR u.username LIKE ?
                     OR DATE(ce.start_time) LIKE ?)
                   {$sw} {$uf}
                 ORDER BY ce.start_time DESC LIMIT 10"
            );
            $date_like = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
            $stmt->execute([$like, $like, $like, $like, $date_like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $start = $r['start_time'] ? date('M d, Y h:i A', strtotime($r['start_time'])) : 'TBD';
                $end   = $r['end_time']   ? date('h:i A', strtotime($r['end_time']))           : '';
                $range = $end ? "{$start} – {$end}" : $start;
                
                $cal_link = 'staff_calendar.php';
                if ($role === 'manager') {
                    $cal_link = 'manager_calendar.php';
                } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $cal_link = 'admin_calendar.php';
                }
                
                $results[] = [
                    'type'     => 'Calendar',
                    'title'    => $r['title'] ?: ucfirst($r['event_type'] ?? 'Event'),
                    'subtitle' => "{$range}" . ($r['assigned_name'] ? " · {$r['assigned_name']}" : ''),
                    'meta'     => $r['event_type'] ?? '',
                    'link'     => $cal_link,
                    'icon'     => $ICONS['Calendar'],
                    'color'    => $COLORS['Calendar'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 7. REPORTS (staff view)
    //    Fields: daily transaction summary, job order completion
    // ════════════════════════════════════════════════════════
    if (is_module_enabled('reports') || in_array($role, ['superadmin', 'developer'])) {
        try {
            $sw = $station_id ? "AND (al.station_id = {$station_id} OR al.station_id IS NULL)" : '';
            $stmt = $pdo->prepare(
                "SELECT al.id, al.action, al.details, al.created_at
                 FROM activity_logs al
                 WHERE (al.action LIKE ?
                     OR al.details LIKE ?)
                   AND (al.action LIKE '%Summary%'
                     OR al.action LIKE '%Report%'
                     OR al.action LIKE '%Completion%'
                     OR al.details LIKE '%daily%'
                     OR al.details LIKE '%summary%'
                     OR al.details LIKE '%report%')
                   {$sw}
                 ORDER BY al.created_at DESC LIMIT 8"
            );
            $stmt->execute([$like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y H:i', strtotime($r['created_at']));
                
                $report_link = 'staff_reports.php';
                if ($role === 'manager') {
                    $report_link = 'manager_reports.php';
                } elseif ($role === 'admin') {
                    $report_link = 'admin_reports.php';
                } elseif (in_array($role, ['superadmin', 'developer'])) {
                    $report_link = 'reports_technical.php';
                }
                
                $results[] = [
                    'type'     => 'Report',
                    'title'    => $r['action'],
                    'subtitle' => mb_strimwidth($r['details'] ?? '', 0, 80, '…') . " · {$ts}",
                    'meta'     => $ts,
                    'link'     => $report_link,
                    'icon'     => $ICONS['Report'],
                    'color'    => $COLORS['Report'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 8. PRODUCT MANAGEMENT (manager/admin only)
    //    Fields: product name, pricing, category
    // ════════════════════════════════════════════════════════
    if ($is_manager) {
        try {
            $stmt = $pdo->prepare(
                "SELECT ip.id, ip.product_name, ip.sku, ip.category,
                        ip.unit_price, ip.cost_price,
                        ip.stock AS stock_level
                 FROM inventory_products ip
                 WHERE (ip.product_name LIKE ?
                     OR ip.sku LIKE ?
                     OR ip.category LIKE ?
                     OR CAST(ip.unit_price AS CHAR) LIKE ?
                     OR CAST(ip.cost_price AS CHAR) LIKE ?)
                 ORDER BY ip.product_name ASC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $price = $r['unit_price'] ? '₱' . number_format($r['unit_price'], 2) : 'No price';
                $cost  = $r['cost_price']  ? ' · Cost: ₱' . number_format($r['cost_price'], 2) : '';
                
                $prod_mgmt_link = 'manager_product_merchandise.php';
                if (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $prod_mgmt_link = 'admin_set_prices.php';
                }
                
                $results[] = [
                    'type'     => 'Product Mgmt',
                    'title'    => $r['product_name'],
                    'subtitle' => "SKU: {$r['sku']} · {$r['category']} · Price: {$price}{$cost}",
                    'meta'     => $r['category'],
                    'link'     => $prod_mgmt_link,
                    'icon'     => $ICONS['Product Mgmt'],
                    'color'    => $COLORS['Product Mgmt'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 9. FUEL MANAGEMENT (manager/admin only)
    //    Fields: pump ID, tank level, refill logs, fuel type
    // ════════════════════════════════════════════════════════
    if ($is_manager) {
        // Fuel inventory tanks
        try {
            $sw2 = $station_id ? "AND fi.station_id = {$station_id}" : '';
            $stmt = $pdo->prepare(
                "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity,
                        fi.station_id
                 FROM fuel_inventory fi
                 WHERE (fi.fuel_type LIKE ?
                     OR CAST(fi.current_level AS CHAR) LIKE ?
                     OR CAST(fi.capacity AS CHAR) LIKE ?)
                   {$sw2}
                 ORDER BY fi.fuel_type ASC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pct  = $r['capacity'] > 0 ? round(($r['current_level'] / $r['capacity']) * 100) : 0;
                
                $fuel_mgmt_link = 'manager_fuel_management_complete.php';
                if (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $fuel_mgmt_link = 'admin_fuel_transactions_oversight.php';
                }
                
                $results[] = [
                    'type'     => 'Fuel Management',
                    'title'    => "Fuel Tank — {$r['fuel_type']}",
                    'subtitle' => "Level: {$r['current_level']}L / {$r['capacity']}L ({$pct}%)",
                    'meta'     => $r['fuel_type'],
                    'link'     => $fuel_mgmt_link,
                    'icon'     => $ICONS['Fuel Management'],
                    'color'    => $COLORS['Fuel Management'],
                ];
            }
        } catch (Exception $e) {}

        // Fuel pump readings
        try {
            $sw2 = $station_id ? "AND fdr.station_id = {$station_id}" : '';
            $stmt = $pdo->prepare(
                "SELECT fdr.id, fdr.pump_number, fdr.computed_liters,
                        fdr.reading_date, fp.fuel_type
                 FROM fuel_daily_readings fdr
                 LEFT JOIN fuel_pumps fp ON fp.id = fdr.pump_id
                 WHERE (CAST(fdr.pump_number AS CHAR) LIKE ?
                     OR fp.fuel_type LIKE ?
                     OR DATE(fdr.reading_date) LIKE ?)
                   {$sw2}
                 ORDER BY fdr.reading_date DESC LIMIT 8"
            );
            $date_like2 = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
            $stmt->execute([$like, $like, $date_like2]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dt   = date('M d, Y', strtotime($r['reading_date']));
                $pump = $r['pump_number'] ?? $r['id'];
                
                $fuel_mgmt_link = 'manager_fuel_management_complete.php';
                if (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    $fuel_mgmt_link = 'admin_fuel_transactions_oversight.php';
                }
                
                $results[] = [
                    'type'     => 'Fuel Management',
                    'title'    => "Pump #{$pump} Reading — {$dt}",
                    'subtitle' => "Fuel: {$r['fuel_type']} · Computed: {$r['computed_liters']}L",
                    'meta'     => $r['fuel_type'] ?? '',
                    'link'     => $fuel_mgmt_link,
                    'icon'     => $ICONS['Fuel Management'],
                    'color'    => $COLORS['Fuel Management'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 10. STATIONS (superadmin / developer only)
    //     Fields: name, address, status, station code
    // ════════════════════════════════════════════════════════
    if (in_array($role, ['superadmin', 'developer'])) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, address, status, station_code
                 FROM stations
                 WHERE (name LIKE ?
                     OR address LIKE ?
                     OR status LIKE ?
                     OR station_code LIKE ?)
                 ORDER BY name ASC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $code = $r['station_code'] ? " · Code: {$r['station_code']}" : '';
                $results[] = [
                    'type'     => 'Station',
                    'title'    => $r['name'],
                    'subtitle' => ($r['address'] ?? 'No address') . $code . " · Status: {$r['status']}",
                    'meta'     => $r['status'],
                    'link'     => 'superadmin_station_management.php',
                    'icon'     => $ICONS['Station'],
                    'color'    => $COLORS['Station'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 11. ADMINS / USERS (superadmin / developer only)
    //     Fields: name, username, role, email, status
    // ════════════════════════════════════════════════════════
    if (in_array($role, ['superadmin', 'developer'])) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, username,
                        COALESCE(NULLIF(CONCAT(first_name,' ',last_name),' '), username) AS full_name,
                        role, email, status
                 FROM users
                 WHERE (username LIKE ?
                     OR first_name LIKE ?
                     OR last_name LIKE ?
                     OR email LIKE ?
                     OR role LIKE ?
                     OR status LIKE ?)
                 ORDER BY full_name ASC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $email = $r['email'] ? " · {$r['email']}" : '';
                $results[] = [
                    'type'     => 'Admin',
                    'title'    => $r['full_name'] . ' (@' . $r['username'] . ')',
                    'subtitle' => ucfirst($r['role']) . $email . " · Status: {$r['status']}",
                    'meta'     => $r['role'],
                    'link'     => 'superadmin_admin_management.php',
                    'icon'     => $ICONS['Admin'],
                    'color'    => $COLORS['Admin'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 12. SYSTEM LOGS (superadmin / developer only)
    //     Source: activity_logs — action, details, IP
    // ════════════════════════════════════════════════════════
    if (in_array($role, ['superadmin', 'developer'])) {
        try {
            $stmt = $pdo->prepare(
                "SELECT al.id, al.action, al.details, al.ip_address,
                        al.created_at,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name
                 FROM activity_logs al
                 LEFT JOIN users u ON u.id = al.user_id
                 WHERE (al.action LIKE ?
                     OR al.details LIKE ?
                     OR al.ip_address LIKE ?
                     OR u.username LIKE ?)
                 ORDER BY al.created_at DESC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y H:i', strtotime($r['created_at']));
                $ip = $r['ip_address'] ? " · IP: {$r['ip_address']}" : '';
                $results[] = [
                    'type'     => 'System Log',
                    'title'    => $r['action'],
                    'subtitle' => mb_strimwidth($r['details'] ?? '', 0, 70, '…') . " · {$r['user_name']}{$ip} · {$ts}",
                    'meta'     => $ts,
                    'link'     => 'activity_logs.php',
                    'icon'     => $ICONS['System Log'],
                    'color'    => $COLORS['System Log'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 13. SECURITY EVENTS (superadmin / developer only)
    //     Source: activity_logs — failed logins, unauthorized
    // ════════════════════════════════════════════════════════
    if (in_array($role, ['superadmin', 'developer'])) {
        try {
            $stmt = $pdo->prepare(
                "SELECT al.id, al.action, al.details, al.ip_address,
                        al.created_at,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name
                 FROM activity_logs al
                 LEFT JOIN users u ON u.id = al.user_id
                 WHERE (al.action LIKE ?
                     OR al.details LIKE ?
                     OR al.ip_address LIKE ?)
                   AND (al.action LIKE '%fail%' OR al.action LIKE '%Failed%'
                     OR al.action LIKE '%Unauthorized%' OR al.action LIKE '%unauthorized%'
                     OR al.action LIKE '%lock%' OR al.action LIKE '%suspicious%'
                     OR al.details LIKE '%denied%' OR al.details LIKE '%brute%')
                 ORDER BY al.created_at DESC LIMIT 8"
            );
            $stmt->execute([$like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y H:i', strtotime($r['created_at']));
                $ip = $r['ip_address'] ? " · IP: {$r['ip_address']}" : '';
                $results[] = [
                    'type'     => 'Security',
                    'title'    => $r['action'],
                    'subtitle' => "User: {$r['user_name']}{$ip} · {$ts}",
                    'meta'     => $ts,
                    'link'     => 'reports_security.php',
                    'icon'     => $ICONS['Security'],
                    'color'    => $COLORS['Security'],
                ];
            }
        } catch (Exception $e) {}
    }

    // ════════════════════════════════════════════════════════
    // 14. AUDIT TRAIL (superadmin / developer / admin / manager)
    //     Source: activity_logs / audit_logs
    // ════════════════════════════════════════════════════════
    if (in_array($role, ['superadmin', 'developer', 'admin', 'manager'])) {
        try {
            if (in_array($role, ['superadmin', 'developer'])) {
                $stmt = $pdo->prepare(
                    "SELECT al.id, al.action, al.details, al.created_at,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name
                     FROM activity_logs al
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE (al.action LIKE ?
                         OR al.details LIKE ?)
                       AND (al.action LIKE '%Config%' OR al.action LIKE '%Setting%'
                         OR al.action LIKE '%Export%' OR al.action LIKE '%Deploy%'
                         OR al.action LIKE '%Backup%' OR al.action LIKE '%Restore%'
                         OR al.action LIKE '%Integration%' OR al.action LIKE '%Module%')
                     ORDER BY al.created_at DESC LIMIT 8"
                );
                $stmt->execute([$like, $like]);
            } elseif ($role === 'admin') {
                $stmt = $pdo->prepare(
                    "SELECT al.id, al.action_type AS action, al.action_details AS details, al.created_at,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name
                     FROM audit_logs al
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE (al.action_type LIKE ?
                         OR al.action_details LIKE ?)
                       AND u.station_id = ?
                     ORDER BY al.created_at DESC LIMIT 8"
                );
                $stmt->execute([$like, $like, $station_id]);
            } else { // manager
                $stmt = $pdo->prepare(
                    "SELECT al.id, al.action_type AS action, al.action_details AS details, al.created_at,
                            COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name
                     FROM audit_logs al
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE (al.action_type LIKE ?
                         OR al.action_details LIKE ?)
                       AND al.user_id = ?
                     ORDER BY al.created_at DESC LIMIT 8"
                );
                $stmt->execute([$like, $like, $user_id]);
            }
            
            $audit_link = 'superadmin_audit_trail.php';
            if ($role === 'admin') {
                $audit_link = 'admin_audit_trail.php';
            } elseif ($role === 'manager') {
                $audit_link = 'manager_audit_trail.php';
            }
            
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = date('M d, Y H:i', strtotime($r['created_at']));
                $results[] = [
                    'type'     => 'Audit Trail',
                    'title'    => $r['action'],
                    'subtitle' => mb_strimwidth($r['details'] ?? '', 0, 70, '…') . " · {$r['user_name']} · {$ts}",
                    'meta'     => $ts,
                    'link'     => $audit_link,
                    'icon'     => $ICONS['Audit Trail'],
                    'color'    => $COLORS['Audit Trail'],
                ];
            }
        } catch (Exception $e) {}
    }
}

// ── AJAX: return JSON for autocomplete (max 4 per type) ──────
if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!empty($query) && strlen(trim($query)) >= 3) {
        log_activity($pdo, $user_id, 'Global Search (Ajax)', "User searched: {$query}");
    }
    
    // Limit to 4 per category for dropdown
    $grouped = [];
    foreach ($results as $r) {
        $t = $r['type'];
        if (!isset($grouped[$t])) $grouped[$t] = [];
        if (count($grouped[$t]) < 4) $grouped[$t][] = $r;
    }
    $flat = [];
    foreach ($grouped as $items) {
        foreach ($items as $i) $flat[] = $i;
    }
    echo json_encode($flat);
    exit;
}

// ── Full results page ─────────────────────────────────────────
$page_id = 'search';
include __DIR__ . '/../partials/header.php';
?>

<style>
.srp-wrap {
    max-width: 1000px;
    margin: 20px auto;
    padding: 0 24px 60px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

/* Header Section */
.srp-header {
    margin-bottom: 32px;
    padding: 24px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}
.srp-header h1 {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 8px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.srp-header h1 i {
    font-size: 24px;
    color: #002F6C;
}
.srp-header p {
    color: #475569;
    font-size: 14px;
    margin: 0;
    font-weight: 500;
}
.srp-header p strong {
    color: #002F6C;
    font-weight: 700;
}

/* Search Form */
.srp-search-form {
    display: flex;
    gap: 12px;
    margin-bottom: 32px;
    padding: 0 4px;
}
.srp-search-form input {
    flex: 1;
    padding: 14px 20px;
    border-radius: 10px;
    border: 2px solid #e2e8f0;
    font-size: 15px;
    outline: none;
    transition: all .2s;
    background: #fff;
    color: #1e293b;
    font-weight: 500;
}
.srp-search-form input:focus { 
    border-color: #002F6C; 
    box-shadow: 0 0 0 4px rgba(0, 47, 108, 0.1);
}
.srp-search-form input::placeholder {
    color: #94a3b8;
}
.srp-search-form button {
    padding: 14px 28px;
    border-radius: 10px;
    border: none;
    background: #002F6C;
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(0, 47, 108, 0.2);
}
.srp-search-form button:hover {
    background: #001f4d;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 47, 108, 0.3);
}
.srp-search-form button:active {
    transform: translateY(0);
}

/* Result Groups */
.srp-group { 
    margin-bottom: 36px;
    padding: 0 4px;
}
.srp-group-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #475569;
    margin-bottom: 16px;
    padding: 10px 16px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
.srp-group-title i {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.srp-count {
    display: inline-block;
    background: #e2e8f0;
    color: #475569;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 10px;
    border-radius: 20px;
    margin-left: 8px;
}

/* Result Items */
.srp-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-radius: 12px;
    border: 2px solid #f1f5f9;
    background: #fff;
    margin-bottom: 10px;
    text-decoration: none;
    color: inherit;
    transition: all .2s;
    position: relative;
}
.srp-item:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
    transform: translateY(-2px);
    border-color: #002F6C;
}
.srp-item:hover .srp-item-icon {
    transform: scale(1.05);
}
.srp-item-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    color: #fff;
    transition: transform .2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}
.srp-item-body { 
    flex: 1; 
    min-width: 0; 
}
.srp-item-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.srp-item-sub {
    font-size: 13px;
    color: #64748b;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.srp-item i.fa-chevron-right {
    color: #cbd5e1;
    font-size: 14px;
    transition: all .2s;
}
.srp-item:hover i.fa-chevron-right {
    color: #002F6C;
    transform: translateX(2px);
}

/* Empty State */
.srp-empty {
    text-align: center;
    padding: 80px 20px;
    color: #94a3b8;
    background: #f8fafc;
    border-radius: 12px;
    border: 2px dashed #e2e8f0;
}
.srp-empty i { 
    font-size: 48px; 
    margin-bottom: 16px; 
    display: block;
    color: #cbd5e1;
}
.srp-empty p { 
    font-size: 16px; 
    margin: 0;
    font-weight: 500;
    color: #64748b;
}
.srp-empty p strong {
    color: #002F6C;
    font-weight: 700;
}

/* Responsive */
@media (max-width: 768px) {
    .srp-wrap {
        padding: 0 16px 40px;
    }
    .srp-header {
        padding: 20px;
    }
    .srp-header h1 {
        font-size: 24px;
    }
    .srp-search-form {
        flex-direction: column;
    }
    .srp-search-form button {
        justify-content: center;
    }
    .srp-item {
        padding: 14px 16px;
    }
    .srp-item-icon {
        width: 40px;
        height: 40px;
        font-size: 14px;
    }
}
</style>

<div class="srp-wrap">
    <div class="srp-header">
        <h1><i class="fas fa-search"></i>Search Results</h1>
        <?php if ($query): ?>
        <p>Showing results for <strong>"<?= htmlspecialchars($query) ?>"</strong>
           — <strong><?= count($results) ?></strong> result<?= count($results) !== 1 ? 's' : '' ?> found</p>
        <?php endif; ?>
    </div>

    <form class="srp-search-form" method="get" action="search.php">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>"
               placeholder="Search stations, admins, transactions, reports..." autofocus>
        <button type="submit"><i class="fas fa-search"></i> Search</button>
    </form>

    <?php if (empty($query)): ?>
    <div class="srp-empty">
        <i class="fas fa-search"></i>
        <p>Enter a keyword to search across the system</p>
    </div>
    <?php elseif (empty($results)): ?>
    <div class="srp-empty">
        <i class="fas fa-inbox"></i>
        <p>No results found for <strong>"<?= htmlspecialchars($query) ?>"</strong></p>
        <p style="font-size:14px;margin-top:8px;color:#94a3b8;">Try different keywords or check your spelling</p>
    </div>
    <?php else:
        // Group results by type
        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r['type']][] = $r;
        }
        foreach ($grouped as $type => $items):
            $icon  = $ICONS[$type]  ?? 'fas fa-circle';
            $color = $COLORS[$type] ?? '#64748b';
    ?>
    <div class="srp-group">
        <div class="srp-group-title">
            <i class="<?= $icon ?>" style="background:<?= $color ?>;"></i>
            <?= htmlspecialchars($type) ?>s
            <span class="srp-count"><?= count($items) ?></span>
        </div>
        <?php foreach ($items as $r): ?>
        <a class="srp-item" href="<?= htmlspecialchars($r['link']) ?>">
            <div class="srp-item-icon" style="background:<?= $color ?>;">
                <i class="<?= $icon ?>"></i>
            </div>
            <div class="srp-item-body">
                <div class="srp-item-title"><?= htmlspecialchars($r['title']) ?></div>
                <div class="srp-item-sub"><?= htmlspecialchars($r['subtitle']) ?></div>
            </div>
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
