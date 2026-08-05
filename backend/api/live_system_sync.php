<?php
/**
 * Live System Sync API
 * backend/api/live_system_sync.php
 *
 * Provides real-time background synchronization data across all pages:
 *  - Header notification counts & top unread items
 *  - Header security alert counts
 *  - Sidebar navigation badge counts (Transactions, Fuel, Inventory, Customers, Pricing, Reports)
 *  - System health / DB connection status & live timestamp
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $_SESSION['user'];
$user_id = (int)($user['id'] ?? 0);
$role = function_exists('role_key') ? role_key($user['role'] ?? '') : strtolower(trim($user['role'] ?? 'staff'));
$station_id = user_station_id();

$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'formatted_time' => date('g:i:s A'),
    'db_connected' => true,
    'unread_notifications' => 0,
    'notifications' => [],
    'unread_alerts' => 0,
    'alerts' => [],
    'sidebar_badges' => [
        'transactions' => 0,
        'fuel'         => 0,
        'inventory'    => 0,
        'customers'    => 0,
        'pricing'      => 0,
        'reports'      => 0,
        'users'        => 0,
    ],
];

// 1. Ensure Notifications Table & Fetch Unread Notifications
try {
    if (function_exists('ensure_notifications_table')) {
        ensure_notifications_table($pdo);
    }
    
    // Unread count
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
    $stmt_count->execute([$user_id]);
    $response['unread_notifications'] = (int)$stmt_count->fetchColumn();

    // Top 10 notifications
    $stmt_list = $pdo->prepare(
        "SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 10"
    );
    $stmt_list->execute([$user_id]);
    $raw_notifs = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

    $formatted_notifs = [];
    foreach ($raw_notifs as $n) {
        $ts = strtotime($n['created_at']);
        $diff = max(0, time() - $ts);
        if ($diff < 60) $time_ago = 'Just now';
        elseif ($diff < 3600) $time_ago = floor($diff / 60) . 'm ago';
        elseif ($diff < 86400) $time_ago = floor($diff / 3600) . 'h ago';
        else $time_ago = date('M j', $ts);

        $formatted_notifs[] = [
            'id' => (int)$n['id'],
            'type' => $n['type'],
            'title' => $n['title'],
            'message' => $n['message'],
            'severity' => $n['severity'],
            'redirect_url' => $n['redirect_url'],
            'status' => $n['status'],
            'time_ago' => $time_ago,
            'created_at' => $n['created_at'],
        ];
    }
    $response['notifications'] = $formatted_notifs;
} catch (Throwable $e) {
    $response['db_connected'] = false;
}

// 2. Fetch Security / Audit Alerts for Dropdown
if (in_array($role, ['superadmin', 'admin', 'manager'])) {
    try {
        $alerts = [];
        if ($role === 'superadmin') {
            $stmt = $pdo->prepare("SELECT user_id, details, created_at FROM activity_logs WHERE action = 'Login Failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fl) {
                $alerts[] = [
                    'type' => 'security',
                    'title' => 'Failed Login Attempt',
                    'message' => (string)($fl['details'] ?? 'Suspicious login activity detected'),
                    'time' => date('g:i A', strtotime($fl['created_at'])),
                ];
            }
        }

        // Low Fuel Tanks Alert
        $sw = $station_id ? "WHERE station_id = {$station_id}" : "";
        $stmt_fuel = $pdo->query("SELECT fuel_type, current_level, capacity FROM fuel_inventory {$sw}");
        if ($stmt_fuel) {
            foreach ($stmt_fuel->fetchAll(PDO::FETCH_ASSOC) as $fi) {
                $cap = (float)($fi['capacity'] ?? 0);
                $lvl = (float)($fi['current_level'] ?? 0);
                $crit = $cap * 0.15;
                if ($cap > 0 && $lvl <= $crit) {
                    $alerts[] = [
                        'type' => 'fuel',
                        'title' => 'Critical Fuel Tank',
                        'message' => "{$fi['fuel_type']}: {$lvl}L / {$cap}L remaining",
                        'time' => 'Low Level',
                    ];
                }
            }
        }

        $response['alerts'] = array_slice($alerts, 0, 8);
        $response['unread_alerts'] = count($response['alerts']);
    } catch (Throwable $e) {}
}

// 3. Category Sidebar Badges Breakdown
$safe_count = function(string $sql, array $params = []) use ($pdo) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
};

if ($role === 'staff') {
    // Staff sidebar badges — parameterized, safe SQL
    if ($station_id) {
        $response['sidebar_badges']['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','in progress','awaiting parts')",
            [$station_id]
        );
        $response['sidebar_badges']['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) = 'pending validation'",
            [$station_id]
        );
        $response['sidebar_badges']['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND stock_level <= reorder_level AND status = 'active'",
            [$station_id]
        );
    }
} elseif (in_array($role, ['admin', 'manager', 'superadmin', 'developer'])) {
    if ($station_id) {
        $pending_users   = $safe_count("SELECT COUNT(*) FROM users WHERE station_id = ? AND LOWER(COALESCE(status,'')) = 'pending'", [$station_id]);
        $pending_cust    = $safe_count("SELECT COUNT(*) FROM customers WHERE station_id = ? AND (LOWER(COALESCE(verification_status,'')) = 'pending' OR LOWER(COALESCE(mgr_status,'')) = 'pending')", [$station_id]);
        $pending_pos     = $safe_count("SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending approval','approved') AND (stock_in_done = 0 OR admin_finalized = 0)", [$station_id]);
        $pending_prices  = $safe_count("SELECT COUNT(*) FROM pending_price_approvals WHERE station_id = ? AND LOWER(COALESCE(status,'')) = 'pending'", [$station_id]);
        $inv_alerts      = $safe_count("SELECT COUNT(*) FROM station_inventory WHERE station_id = ? AND COALESCE(stock_level,0) <= COALESCE(critical_level,5)", [$station_id]);
    } else {
        $pending_users   = $safe_count("SELECT COUNT(*) FROM users WHERE LOWER(COALESCE(status,'')) = 'pending'");
        $pending_cust    = $safe_count("SELECT COUNT(*) FROM customers WHERE (LOWER(COALESCE(verification_status,'')) = 'pending' OR LOWER(COALESCE(mgr_status,'')) = 'pending')");
        $pending_pos     = $safe_count("SELECT COUNT(*) FROM purchase_orders WHERE LOWER(COALESCE(status,'')) IN ('pending','pending approval','approved') AND (stock_in_done = 0 OR admin_finalized = 0)");
        $pending_prices  = $safe_count("SELECT COUNT(*) FROM pending_price_approvals WHERE LOWER(COALESCE(status,'')) = 'pending'");
        $inv_alerts      = $safe_count("SELECT COUNT(*) FROM station_inventory WHERE COALESCE(stock_level,0) <= COALESCE(critical_level,5)");
    }

    $response['sidebar_badges']['users']        = $pending_users;
    $response['sidebar_badges']['customers']    = $pending_cust;
    $response['sidebar_badges']['inventory']    = $inv_alerts + $pending_pos;
    $response['sidebar_badges']['pricing']      = $pending_prices;
    $response['sidebar_badges']['transactions'] = $pending_pos + $pending_prices;
    $response['sidebar_badges']['reports']      = $safe_count("SELECT COUNT(*) FROM activity_logs WHERE action LIKE '%fail%' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
