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

// ── Route ─────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        switch ($action) {

            // ── List notifications ────────────────────────────
            case 'list':
                $status = $_GET['status'] ?? 'all';
                $limit  = min((int)($_GET['limit'] ?? 20), 50);
                $offset = (int)($_GET['offset'] ?? 0);

                $where  = 'WHERE n.user_id = ?';
                $params = [$user_id];

                if ($status !== 'all') {
                    $where   .= ' AND n.status = ?';
                    $params[] = $status;
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

                echo json_encode([
                    'success'       => true,
                    'notifications' => $rows,
                    'unread_count'  => $unread,
                    'total'         => count($rows),
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
                        "SELECT COUNT(*) FROM station_inventory si INNER JOIN inventory_products ip ON ip.id=si.product_id WHERE {$low_merch_where}COALESCE(si.stock_level,0) <= COALESCE(si.reorder_level, ip.min_stock, 10) AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels')",
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
                        "SELECT COUNT(*) FROM stock_requests WHERE {$station_where}status='Pending'",
                        $station_param
                    );
                    // Pending fuel stock requests
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$station_where}status='Pending'",
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
                    // Own pending stock requests
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status='Pending'",
                        [$user_id]
                    );
                }

                echo json_encode([
                    'success'      => true,
                    'unread_count' => $action_count,
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
                echo json_encode([
                    'success'      => true,
                    'unread_count' => (int)$cnt->fetchColumn(),
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
                    'success'      => true,
                    'unread_count' => 0,
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
