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
                        u.name AS staff_name
                 FROM merchandise_transactions mt
                 LEFT JOIN users u ON u.id = mt.staff_id
                 WHERE (mt.transaction_id LIKE ?
                     OR mt.status LIKE ?
                     OR mt.payment_method LIKE ?
                     OR u.name LIKE ?
                     OR DATE(mt.created_at) LIKE ?)
                   {$sw}
                 ORDER BY mt.created_at DESC LIMIT 10"
            );
            $date_like = '%' . str_replace(['/', '-', ' '], '%', $query) . '%';
            $stmt->execute([$like, $like, $like, $like, $date_like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $txn_id = $r['transaction_id'] ?? ('#' . $r['id']);
                $ts     = date('M d, Y H:i', strtotime($r['created_at']));
                $results[] = [
                    'type'     => 'Transaction',
                    'title'    => "Transaction {$txn_id}",
                    'subtitle' => "Status: {$r['status']} · {$ts}",
                    'meta'     => $r['payment_method'] ?? '',
                    'link'     => 'staff_transactions_hub.php',
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
                $results[] = [
                    'type'     => 'Transaction',
                    'title'    => "Fuel Transaction #{$r['id']} — {$r['fuel_type']}",
                    'subtitle' => "Status: {$r['status']} · {$ts}",
                    'meta'     => $r['shift_period'] ?? '',
                    'link'     => 'staff_transactions_hub.php?section=fuel',
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
                $results[] = [
                    'type'     => 'Customer',
                    'title'    => $r['name'],
                    'subtitle' => "{$contact}{$code} · Status: {$r['status']}",
                    'meta'     => $r['status'],
                    'link'     => 'customers.php?edit=' . $r['id'],
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
                $results[] = [
                    'type'     => 'Product',
                    'title'    => $r['product_name'],
                    'subtitle' => "SKU: {$r['sku']} · {$r['category']} · {$avail}",
                    'meta'     => $r['category'],
                    'link'     => 'staff_inventory.php',
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
                        jo.created_at, u.name AS staff_name
                 FROM job_orders jo
                 LEFT JOIN users u ON u.id = jo.created_by
                 WHERE (jo.job_order_id LIKE ?
                     OR jo.customer_name LIKE ?
                     OR jo.service_type LIKE ?
                     OR jo.status LIKE ?
                     OR u.name LIKE ?)
                   {$sw} {$uf}
                 ORDER BY jo.created_at DESC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $jo_num = $r['job_order_id'] ?? ('#' . $r['id']);
                $jo_link = $is_manager ? 'manager_job_orders.php' : ('joborder.php?view=' . $r['id']);
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
                        u.name AS staff_name
                 FROM deliveries_oversight do2
                 LEFT JOIN users u ON u.id = do2.encoded_by
                 WHERE (CAST(do2.id AS CHAR) LIKE ?
                     OR do2.status LIKE ?
                     OR do2.supplier LIKE ?
                     OR do2.delivery_type LIKE ?
                     OR u.name LIKE ?)
                   {$sw} {$uf}
                 ORDER BY do2.delivery_date DESC LIMIT 10"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dt = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';
                $results[] = [
                    'type'     => 'Delivery',
                    'title'    => "Delivery #{$r['id']} — {$r['supplier']}",
                    'subtitle' => "Status: {$r['status']} · {$dt} · {$r['delivery_type']}",
                    'meta'     => $r['status'],
                    'link'     => 'staff_record_delivery.php',
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
                        u.name AS assigned_name
                 FROM calendar_events ce
                 LEFT JOIN users u ON u.id = ce.user_id
                 WHERE (ce.title LIKE ?
                     OR ce.event_type LIKE ?
                     OR ce.description LIKE ?
                     OR u.name LIKE ?
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
                $results[] = [
                    'type'     => 'Calendar',
                    'title'    => $r['title'] ?: ucfirst($r['event_type'] ?? 'Event'),
                    'subtitle' => "{$range}" . ($r['assigned_name'] ? " · {$r['assigned_name']}" : ''),
                    'meta'     => $r['event_type'] ?? '',
                    'link'     => 'staff_calendar.php',
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
                $report_link = $is_manager ? 'manager_reports.php' : 'staff_reports.php';
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
                $results[] = [
                    'type'     => 'Product Mgmt',
                    'title'    => $r['product_name'],
                    'subtitle' => "SKU: {$r['sku']} · {$r['category']} · Price: {$price}{$cost}",
                    'meta'     => $r['category'],
                    'link'     => 'manager_product_merchandise.php',
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
                $results[] = [
                    'type'     => 'Fuel Management',
                    'title'    => "Fuel Tank — {$r['fuel_type']}",
                    'subtitle' => "Level: {$r['current_level']}L / {$r['capacity']}L ({$pct}%)",
                    'meta'     => $r['fuel_type'],
                    'link'     => 'manager_fuel_management_complete.php',
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
                $results[] = [
                    'type'     => 'Fuel Management',
                    'title'    => "Pump #{$pump} Reading — {$dt}",
                    'subtitle' => "Fuel: {$r['fuel_type']} · Computed: {$r['computed_liters']}L",
                    'meta'     => $r['fuel_type'] ?? '',
                    'link'     => 'manager_fuel_management_complete.php',
                    'icon'     => $ICONS['Fuel Management'],
                    'color'    => $COLORS['Fuel Management'],
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
    max-width: 900px;
    margin: 32px auto;
    padding: 0 20px 60px;
    font-family: 'Inter', system-ui, sans-serif;
}
.srp-header {
    margin-bottom: 28px;
}
.srp-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 6px;
}
.srp-header p {
    color: #64748b;
    font-size: 14px;
    margin: 0;
}
.srp-search-form {
    display: flex;
    gap: 8px;
    margin-bottom: 28px;
}
.srp-search-form input {
    flex: 1;
    padding: 10px 18px;
    border-radius: 25px;
    border: 2px solid #e2e8f0;
    font-size: 14px;
    outline: none;
    transition: border-color .2s;
}
.srp-search-form input:focus { border-color: #002F6C; }
.srp-search-form button {
    padding: 10px 22px;
    border-radius: 25px;
    border: none;
    background: #002F6C;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
.srp-group { margin-bottom: 32px; }
.srp-group-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #64748b;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #f1f5f9;
}
.srp-group-title i {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: #fff;
}
.srp-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    border-radius: 10px;
    border: 1px solid #f1f5f9;
    background: #fff;
    margin-bottom: 8px;
    text-decoration: none;
    color: inherit;
    transition: box-shadow .15s, transform .15s;
}
.srp-item:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,.08);
    transform: translateY(-1px);
    border-color: #e2e8f0;
}
.srp-item-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
    color: #fff;
}
.srp-item-body { flex: 1; min-width: 0; }
.srp-item-title {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.srp-item-sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.srp-empty {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}
.srp-empty i { font-size: 40px; margin-bottom: 12px; display: block; }
.srp-empty p { font-size: 15px; margin: 0; }
.srp-count {
    display: inline-block;
    background: #f1f5f9;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    margin-left: 6px;
}
</style>

<div class="srp-wrap">
    <div class="srp-header">
        <h1><i class="fas fa-search" style="color:#002F6C;margin-right:8px;"></i>Search Results</h1>
        <?php if ($query): ?>
        <p>Showing results for <strong>"<?= htmlspecialchars($query) ?>"</strong>
           — <?= count($results) ?> result<?= count($results) !== 1 ? 's' : '' ?> found</p>
        <?php endif; ?>
    </div>

    <form class="srp-search-form" method="get" action="search.php">
        <input type="text" name="q" value="<?= htmlspecialchars($query) ?>"
               placeholder="Search transactions, customers, products, job orders…" autofocus>
        <button type="submit"><i class="fas fa-search"></i> Search</button>
    </form>

    <?php if (empty($query)): ?>
    <div class="srp-empty">
        <i class="fas fa-search"></i>
        <p>Enter a keyword to search across all your modules.</p>
    </div>
    <?php elseif (empty($results)): ?>
    <div class="srp-empty">
        <i class="fas fa-inbox"></i>
        <p>No results found for <strong>"<?= htmlspecialchars($query) ?>"</strong>.</p>
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
            <div class="srp-item-icon" style="background:<?= $color ?>20;">
                <i class="<?= $icon ?>" style="color:<?= $color ?>;"></i>
            </div>
            <div class="srp-item-body">
                <div class="srp-item-title"><?= htmlspecialchars($r['title']) ?></div>
                <div class="srp-item-sub"><?= htmlspecialchars($r['subtitle']) ?></div>
            </div>
            <i class="fas fa-chevron-right" style="color:#cbd5e1;font-size:12px;"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
