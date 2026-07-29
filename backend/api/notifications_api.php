<?php
/**
 * Notifications API
 * backend/api/notifications_api.php
 *
 * Serves all roles: staff, manager, admin, superadmin, developer.
 * Each user only sees their own notifications (filtered by user_id).
 *
 * Actions (GET):
 *   ?action=list          — paginated list of notifications
 *   ?action=unread_count  — count of actionable sidebar badge items (mirrors header bell)
 *
 * Actions (POST):
 *   ?action=mark_read     — mark one notification as read (POST: notification_id)
 *   ?action=mark_all_read — mark all as read for this user
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────
require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

$allowed = ['staff', 'manager', 'admin', 'superadmin', 'developer'];
if (!in_array($role, $allowed) || $user_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

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
} catch (Throwable $e) {}

/**
 * Category breakdown helper for sidebar drawer badges
 */
/**
 * Category breakdown helper for sidebar drawer badges
 */
function get_category_unread_counts(PDO $pdo, int $user_id, string $role = '', int $station_id = 0): array {
    $counts = [
        'transactions'  => 0,
        'fuel'          => 0,
        'inventory'     => 0,
        'customers'     => 0,
        'prod_pricing'  => 0,
        'reports'       => 0,
        'notifications' => 0
    ];

    $safe_count = function(string $sql, array $params = []) use ($pdo) {
        try {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    $counts['notifications'] = $safe_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'", [$user_id]);

    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // SERVICE STAFF
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND (created_by = ? OR user_id = ? OR assigned_mechanic_id = ?) AND LOWER(COALESCE(status,'')) IN ('pending','reviewed','in progress','awaiting parts')",
            [$station_id, $user_id, $user_id, $user_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 24)",
            [$station_id]
        );

    } elseif (in_array($role, ['manager', 'supervisor'])) {
        // MANAGER
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','reviewed')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(adjustment_type,'')) LIKE '%calibration%' AND LOWER(COALESCE(status,'')) IN ('pending','pending review')",
            [$station_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 24)",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Approved','Pending Stock-In')",
            [$station_id]
        );

        $counts['customers']     = $safe_count(
            "SELECT COUNT(*) FROM customers WHERE station_id = ? AND LOWER(COALESCE(NULLIF(verification_status,''), NULLIF(mgr_status,''), 'verified')) IN ('pending','pending verification','for review')",
            [$station_id]
        );
        $counts['mgr_customers'] = $counts['customers'];

    } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
        // ADMIN
        $admin_crit_stock = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.critical_level, ip.critical_level, 10)",
            []
        );
        $admin_pos = $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending Admin Review', 'Submitted', 'Pending Approval')",
            []
        );
        $admin_inv_total = $admin_crit_stock + $admin_pos;
        $counts['inventory']       = $admin_inv_total;
        $counts['admin_inventory'] = $admin_inv_total;

        $admin_price_change = $safe_count(
            "SELECT COUNT(*) FROM pending_price_approvals WHERE status = 'pending'",
            []
        );
        $counts['prod_pricing']          = $admin_price_change;
        $counts['mgr_product_pricing']   = $admin_price_change;
        $counts['admin_product_pricing'] = $admin_price_change;

        $admin_system_alerts = $safe_count(
            "SELECT COUNT(*) FROM notifications WHERE severity IN ('critical','error') AND status = 'unread'",
            []
        );
        $counts['reports']       = $admin_system_alerts;
        $counts['admin_reports'] = $admin_system_alerts;
    }

    return $counts;
}

// ── Route ─────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        switch ($action) {

            // ── List notifications ────────────────────────────
            case 'list':
                $status    = $_GET['status'] ?? 'all';
                $type_f    = $_GET['type'] ?? 'all';
                $sev_f     = $_GET['severity'] ?? 'all';
                $search    = trim($_GET['search'] ?? '');
                $date_from = trim($_GET['date_from'] ?? '');
                $date_to   = trim($_GET['date_to'] ?? '');
                $limit     = min((int)($_GET['limit'] ?? 20), 500);
                $offset    = (int)($_GET['offset'] ?? 0);

                $where  = 'WHERE n.user_id = ?';
                $params = [$user_id];

                if ($status !== 'all' && $status !== '') {
                    $where   .= ' AND n.status = ?';
                    $params[] = $status;
                }
                if ($type_f !== 'all' && $type_f !== '') {
                    $where   .= ' AND (n.event_type = ? OR n.type = ?)';
                    $params[] = $type_f;
                    $params[] = $type_f;
                }
                if ($sev_f !== 'all' && $sev_f !== '') {
                    $where   .= ' AND n.severity = ?';
                    $params[] = $sev_f;
                }
                if ($search !== '') {
                    $where   .= ' AND (n.title LIKE ? OR n.message LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
                if ($date_from !== '') {
                    $where   .= ' AND DATE(n.created_at) >= ?';
                    $params[] = $date_from;
                }
                if ($date_to !== '') {
                    $where   .= ' AND DATE(n.created_at) <= ?';
                    $params[] = $date_to;
                }

                $stmt = $pdo->prepare(
                    "SELECT n.id, n.type, n.title, n.message, n.event_type,
                            n.severity, n.redirect_url, n.status, n.created_at
                     FROM notifications n
                     {$where}
                     ORDER BY n.created_at DESC
                     LIMIT {$limit} OFFSET {$offset}"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as &$n) {
                    $n['time_ago'] = time_ago($n['created_at']);
                    $n['is_unread'] = ($n['status'] === 'unread');
                }
                unset($n);

                // Unread count
                $cnt_stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'"
                );
                $cnt_stmt->execute([$user_id]);
                $unread = (int)$cnt_stmt->fetchColumn();

                // Category breakdown for sidebar badges
                $myStationId = (int)($me['station_id'] ?? 0);
                $cat_counts  = get_category_unread_counts($pdo, $user_id, $role, $myStationId);

                // Overall Stats breakdown for cards
                $stats_stmt = $pdo->prepare(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
                        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
                        SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived
                     FROM notifications WHERE user_id = ?"
                );
                $stats_stmt->execute([$user_id]);
                $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0, 'unread'=>0, 'read_count'=>0, 'archived'=>0];

                echo json_encode([
                    'success'           => true,
                    'notifications'     => $rows,
                    'unread_count'      => $unread,
                    'bell_unread_count' => $unread,
                    'total'             => (int)($stats['total'] ?? count($rows)),
                    'stats'             => [
                        'total'    => (int)($stats['total'] ?? 0),
                        'unread'   => (int)($stats['unread'] ?? 0),
                        'read'     => (int)($stats['read_count'] ?? 0),
                        'archived' => (int)($stats['archived'] ?? 0),
                    ],
                    'category_counts'   => $cat_counts,
                ]);
                break;

            // ── Unread count (sidebar-equivalent action badge) ─
            case 'unread_count':
                $myStationId  = (int)($me['station_id'] ?? 0);
                $station_param = $myStationId ? [$myStationId] : [];
                $station_where = $myStationId ? "station_id = ? AND " : "";

                // Helper for safe count
                $safe_count = function(string $sql, array $params = []) use ($pdo) {
                    try {
                        $s = $pdo->prepare($sql);
                        $s->execute($params);
                        return (int)$s->fetchColumn();
                    } catch (Throwable $e) { return 0; }
                };

                $action_count = 0;

                if (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    // Pending merch transactions
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM merchandise_transactions WHERE {$station_where}LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
                        $station_param
                    );
                    // Pending fuel transactions
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_transactions WHERE {$station_where}LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
                        $station_param
                    );
                    // Pending job orders
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM job_orders WHERE {$station_where}LOWER(COALESCE(validation_status,status,'')) IN ('pending','pending validation','pending_validation')",
                        $station_param
                    );
                    // Pending merchandise POs
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM purchase_orders WHERE {$station_where}status IN ('Pending','Pending Approval','Pending Admin Validation','Submitted') AND COALESCE(admin_finalized,0)=0",
                        $station_param
                    );
                    // Pending fuel POs
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_purchase_orders WHERE {$station_where}status IN ('Pending','Pending Approval','Pending Admin Validation','Submitted')",
                        $station_param
                    );
                    // Low merch stock
                    $low_merch_params = $myStationId ? [$myStationId] : [];
                    $low_merch_where  = $myStationId ? "si.station_id=? AND " : "";
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM station_inventory si INNER JOIN inventory_products ip ON ip.id=si.product_id WHERE {$low_merch_where}COALESCE(si.stock_level,0) <= COALESCE(si.reorder_level, ip.min_stock, 24) AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels')",
                        $low_merch_params
                    );
                    // Pending customers
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM customers WHERE {$station_where}LOWER(COALESCE(NULLIF(verification_status,''), NULLIF(mgr_status,''), 'verified')) IN ('pending','pending verification','pending_validation','for review')",
                        $station_param
                    );
                    // Pending price approvals
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM pending_price_approvals WHERE {$station_where}status='pending'",
                        $station_param
                    );
                    // Awaiting stock-in
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM purchase_orders WHERE {$station_where}admin_finalized=1 AND (COALESCE(stock_in_done,0)=0 OR status IN ('Approved','Approved PO','Admin Finalized'))",
                        $station_param
                    );
                    // Fuel adjustments pending
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_adjustments WHERE {$station_where}(LOWER(COALESCE(status,''))='pending' OR status IS NULL OR status='')",
                        $station_param
                    );

                } elseif ($role === 'manager') {
                    // Pending merch stock requests
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM stock_requests WHERE {$station_where}status IN ('Pending', 'Pending Manager Review')",
                        $station_param
                    );
                    // Pending fuel stock requests
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$station_where}status IN ('Pending', 'Pending Manager Review')",
                        $station_param
                    );
                    // Pending deliveries
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM deliveries_oversight WHERE {$station_where}status IN ('Pending','Ordered','Expected Delivery')",
                        $station_param
                    );
                    // Low inventory
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM inventory WHERE {$station_where}stock_level <= 20",
                        $station_param
                    );
                    // Pending customers
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM customers WHERE {$station_where}LOWER(COALESCE(NULLIF(verification_status,''), NULLIF(mgr_status,''), 'verified')) IN ('pending','pending verification','pending_validation','for review')",
                        $station_param
                    );

                } elseif ($role === 'staff') {
                    // Own active job orders
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM job_orders WHERE user_id = ? AND status IN ('Pending','In Progress','Awaiting Parts')",
                        [$user_id]
                    );
                    // Pending stock-in
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM deliveries_oversight WHERE {$station_where}status='Ready for Stock-In'",
                        $station_param
                    );
                    // Own pending stock requests (merchandise + fuel)
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')",
                        [$user_id]
                    );
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')",
                        [$user_id]
                    );
                }

                // Also fetch actual bell unread count from notifications table
                $bell_stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'"
                );
                $bell_stmt->execute([$user_id]);
                $bell_unread = (int)$bell_stmt->fetchColumn();
                $cat_counts  = get_category_unread_counts($pdo, $user_id, $role, $myStationId);

                echo json_encode([
                    'success'           => true,
                    'unread_count'      => $action_count,      // sidebar badge counts
                    'bell_unread_count' => $bell_unread,       // bell icon badge count
                    'category_counts'   => $cat_counts,        // sidebar drawer category counts
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }

    } elseif ($method === 'POST') {
        switch ($action) {

            // ── Mark one as read ──────────────────────────────
            case 'mark_read':
                $notif_id = (int)($_POST['notification_id'] ?? 0);
                if ($notif_id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE notifications
                         SET status = 'read', read_at = NOW()
                         WHERE id = ? AND user_id = ? AND status = 'unread'"
                    );
                    $stmt->execute([$notif_id, $user_id]);
                }
                $cnt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'"
                );
                $cnt->execute([$user_id]);
                $cat_counts = get_category_unread_counts($pdo, $user_id);
                echo json_encode([
                    'success'           => true,
                    'unread_count'      => (int)$cnt->fetchColumn(),
                    'bell_unread_count' => (int)$cnt->fetchColumn(),
                    'category_counts'   => $cat_counts,
                ]);
                break;

            // ── Archive one notification ──────────────────────
            case 'archive':
                $notif_id = (int)($_POST['notification_id'] ?? 0);
                if ($notif_id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE notifications
                         SET status = 'archived'
                         WHERE id = ? AND user_id = ?"
                    );
                    $stmt->execute([$notif_id, $user_id]);
                }
                $myStationId = (int)($me['station_id'] ?? 0);
                $cnt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'"
                );
                $cnt->execute([$user_id]);
                $cat_counts = get_category_unread_counts($pdo, $user_id, $role, $myStationId);
                echo json_encode([
                    'success'           => true,
                    'unread_count'      => (int)$cnt->fetchColumn(),
                    'bell_unread_count' => (int)$cnt->fetchColumn(),
                    'category_counts'   => $cat_counts,
                ]);
                break;

            // ── Mark all as read ──────────────────────────────
            case 'mark_all_read':
                $stmt = $pdo->prepare(
                    "UPDATE notifications
                     SET status = 'read', read_at = NOW()
                     WHERE user_id = ? AND status = 'unread'"
                );
                $stmt->execute([$user_id]);
                echo json_encode([
                    'success'           => true,
                    'unread_count'      => 0,
                    'bell_unread_count' => 0,
                    'category_counts'   => [
                        'transactions' => 0,
                        'fuel'         => 0,
                        'inventory'    => 0,
                        'customers'    => 0,
                    ],
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log('notifications_api.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ── Helper ────────────────────────────────────────────────────
function time_ago(string $datetime): string {
    $diff = max(0, time() - strtotime($datetime));
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}
