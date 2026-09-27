<?php
/**
 * Petron POS — Unified Real-Time Refresh API
 * GET/POST ?action=<action>
 *
 * Returns JSON data for the auto-refresh engine (petron_realtime.js).
 * All responses are station-scoped and session-gated.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// ── Gate: must be logged in ──────────────────────────────────────────────────
require_login();

// ── CORS / Content-Type ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── CSRF check on non-GET requests ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
        exit;
    }
}

// ── Session context ──────────────────────────────────────────────────────────
$user       = $_SESSION['user'] ?? [];
$user_id    = (int)($user['id'] ?? $_SESSION['user_id'] ?? 0);
$role       = function_exists('role_key') ? role_key($user['role'] ?? '') : strtolower(trim($user['role'] ?? 'staff'));
$station_id = (int)(function_exists('user_station_id') ? user_station_id() : ($user['station_id'] ?? 0));

$action = trim($_GET['action'] ?? $_POST['action'] ?? 'notifications');

// ── Helper: safe integer ─────────────────────────────────────────────────────
function _int($v, int $default = 0): int {
    return is_numeric($v) ? (int)$v : $default;
}

// ── Helper: station WHERE clause ────────────────────────────────────────────
function station_where(string $col, int $sid, string $role): string {
    if ($role === 'superadmin' || $sid === 0) return '1=1';
    return "$col = $sid";
}

// ════════════════════════════════════════════════════════════════════════════
//  ACTIONS
// ════════════════════════════════════════════════════════════════════════════
try {

    // ── NOTIFICATIONS ────────────────────────────────────────────────────────
    if ($action === 'notifications') {
        $stmt = $pdo->prepare(
            "SELECT id, type, title, message, event_type, severity,
                    redirect_url, status, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 20"
        );
        $stmt->execute([$user_id]);
        $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
        $cnt->execute([$user_id]);
        $unread = (int)$cnt->fetchColumn();

        echo json_encode(['ok' => true, 'unread' => $unread, 'notifications' => $notifs]);
        exit;
    }

    // ── BADGES (sidebar counts) ──────────────────────────────────────────────
    if ($action === 'badges') {
        $b = [];

        if ($role === 'superadmin') {
            try { $b['joborder_stats'] = (int)$pdo->query("SELECT COUNT(*) FROM job_orders WHERE status='Pending'")->fetchColumn(); } catch(Exception $e){}
            try { $b['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='Disabled'")->fetchColumn(); } catch(Exception $e){}
            try { $b['oversight'] = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE stock_level <= 20")->fetchColumn(); } catch(Exception $e){}
        } elseif (in_array($role, ['admin', 'manager'])) {
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending'");
                $s->execute([$station_id]); $b['joborder'] = (int)$s->fetchColumn();
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE station_id=? AND stock_level<=20");
                $s->execute([$station_id]); $b['inventory'] = (int)$s->fetchColumn();
            } catch(Exception $e){}
            if ($role === 'admin') {
                try {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status='Pending'");
                    $s->execute([$station_id]); $b['admin_transactions_oversight'] = (int)$s->fetchColumn();
                } catch(Exception $e){}
                try {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation') AND type='merch'");
                    $s->execute([$station_id]); $b['purchase_orders_admin'] = (int)$s->fetchColumn();
                } catch(Exception $e){}
                try {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'");
                    $s->execute([$station_id]); $b['admin_stock_in'] = (int)$s->fetchColumn();
                } catch(Exception $e){}
                try {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Pending Validation','Pending Manager Approval','Confirmed')");
                    $s->execute([$station_id]); $b['deliveries_oversight'] = (int)$s->fetchColumn();
                } catch(Exception $e){}
            }
            if ($role === 'manager') {
                try {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Manager Approval'");
                    $s->execute([$station_id]); $b['manager_deliveries'] = (int)$s->fetchColumn();
                } catch(Exception $e){}
                try {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval') AND type='merch'");
                    $s->execute([$station_id]); $b['mgr_inv_po_gen'] = (int)$s->fetchColumn();
                } catch(Exception $e){}
            }
        } elseif ($role === 'staff') {
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending'");
                $s->execute([$station_id]); $b['job_encode'] = (int)$s->fetchColumn();
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND status='Pending'");
                $s->execute([$station_id]); $b['staff_stock_requests'] = (int)$s->fetchColumn();
            } catch(Exception $e){}
        }

        echo json_encode(['ok' => true, 'badges' => $b, 'station_id' => $station_id]);
        exit;
    }

    // ── DASHBOARD KPIs ───────────────────────────────────────────────────────
    if ($action === 'dashboard') {
        $kpi = [];
        $today = date('Y-m-d');

        if ($role === 'staff') {
            // Today's transactions for this station
            try {
                $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=?");
                $s->execute([$station_id, $today]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                $kpi['txn_count'] = (int)$row['cnt'];
                $kpi['txn_total'] = (float)$row['total'];
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND DATE(created_at)=?");
                $s->execute([$station_id, $today]);
                $kpi['jo_count'] = (int)$s->fetchColumn();
            } catch(Exception $e){}

        } elseif (in_array($role, ['manager', 'admin'])) {
            try {
                $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=?");
                $s->execute([$station_id, $today]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                $kpi['txn_count']      = (int)$row['cnt'];
                $kpi['txn_total']      = (float)$row['total'];
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending'");
                $s->execute([$station_id]); $kpi['jo_pending'] = (int)$s->fetchColumn();
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE station_id=? AND stock_level<=20");
                $s->execute([$station_id]); $kpi['low_stock'] = (int)$s->fetchColumn();
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COALESCE(SUM(credit_balance),0) FROM customers WHERE station_id=?");
                $s->execute([$station_id]); $kpi['ar_total'] = (float)$s->fetchColumn();
            } catch(Exception $e){}
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval')");
                $s->execute([$station_id]); $kpi['po_pending'] = (int)$s->fetchColumn();
            } catch(Exception $e){}

        } elseif ($role === 'superadmin') {
            try {
                $row = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total FROM merchandise_transactions WHERE DATE(created_at)='$today'")->fetch(PDO::FETCH_ASSOC);
                $kpi['txn_count'] = (int)$row['cnt'];
                $kpi['txn_total'] = (float)$row['total'];
            } catch(Exception $e){}
            try { $kpi['jo_pending']  = (int)$pdo->query("SELECT COUNT(*) FROM job_orders WHERE status='Pending'")->fetchColumn(); } catch(Exception $e){}
            try { $kpi['low_stock']   = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE stock_level<=20")->fetchColumn(); } catch(Exception $e){}
            try { $kpi['active_users']= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='Active'")->fetchColumn(); } catch(Exception $e){}
        }

        echo json_encode(['ok' => true, 'kpi' => $kpi, 'as_of' => date('Y-m-d H:i:s')]);
        exit;
    }

    // ── TRANSACTIONS ─────────────────────────────────────────────────────────
    if ($action === 'transactions') {
        $limit  = min(_int($_GET['limit'] ?? 20), 100);
        $offset = _int($_GET['offset'] ?? 0);
        $where  = $role === 'superadmin' ? '1=1' : "station_id = $station_id";

        try {
            $stmt = $pdo->query(
                "SELECT id, transaction_type, total_amount, payment_method,
                        validation_status, created_at, customer_id
                 FROM merchandise_transactions
                 WHERE $where
                 ORDER BY created_at DESC
                 LIMIT $limit OFFSET $offset"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM merchandise_transactions WHERE $where")->fetchColumn();
            echo json_encode(['ok' => true, 'rows' => $rows, 'total' => $cnt]);
        } catch(Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── JOB ORDERS ───────────────────────────────────────────────────────────
    if ($action === 'job_orders') {
        $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
        $status_filter = '';
        if (!empty($_GET['status'])) {
            $st = $pdo->quote($_GET['status']);
            $status_filter = " AND status = $st";
        }
        try {
            $stmt = $pdo->query(
                "SELECT id, customer_name, vehicle_plate, service_type,
                        status, total_amount, created_at
                 FROM job_orders
                 WHERE $where $status_filter
                 ORDER BY created_at DESC
                 LIMIT 50"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rows' => $rows]);
        } catch(Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── INVENTORY / MERCHANDISE ──────────────────────────────────────────────
    if ($action === 'inventory') {
        $rows = [];
        try {
            // First check station_inventory + inventory_products (standard Petron POS schema)
            $stmt = $pdo->prepare("
                SELECT si.id, COALESCE(ip.product_name, si.product_id) AS product_name,
                       COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
                       COALESCE(si.unit, ip.size, 'pcs') AS unit,
                       COALESCE(si.reorder_level, ip.min_stock, 20) AS reorder_level,
                       COALESCE(si.price, ip.unit_price, 0) AS selling_price,
                       COALESCE(ip.category, 'General') AS category,
                       si.updated_at
                FROM station_inventory si
                LEFT JOIN inventory_products ip ON ip.id = si.product_id
                WHERE " . ($role === 'superadmin' ? '1=1' : 'si.station_id = ?') . "
                ORDER BY product_name ASC
                LIMIT 200
            ");
            if ($role === 'superadmin') {
                $stmt->execute();
            } else {
                $stmt->execute([$station_id]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e1) {
            // Fallback to legacy inventory table if exists
            try {
                $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
                $rows = $pdo->query("SELECT * FROM inventory WHERE $where ORDER BY product_name ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                $rows = [];
            }
        }
        $low = array_filter($rows, fn($r) => (int)($r['stock_level'] ?? 0) <= (int)($r['reorder_level'] ?? 20));
        echo json_encode(['ok' => true, 'rows' => $rows, 'low_stock_count' => count($low)]);
        exit;
    }

    // ── FUEL ─────────────────────────────────────────────────────────────────
    if ($action === 'fuel') {
        $kpi = ['readings' => [], 'inventory' => []];
        try {
            if ($role === 'superadmin') {
                $s = $pdo->query("SELECT fuel_type, COALESCE(SUM(volume),0) as total FROM fuel_inventory GROUP BY fuel_type");
                $kpi['inventory'] = $s->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $s = $pdo->prepare("SELECT fuel_type, volume, capacity, ugt_no FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type ASC");
                $s->execute([$station_id]);
                $kpi['inventory'] = $s->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {}

        try {
            $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
            $s = $pdo->query("SELECT id, fuel_type, pump_label, opening_reading, present_reading, (present_reading - opening_reading) AS liters_sold, shift_period, created_at FROM fuel_transactions WHERE $where ORDER BY id DESC LIMIT 20");
            $kpi['readings'] = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e) {}

        echo json_encode(['ok' => true, 'fuel' => $kpi]);
        exit;
    }

    // ── PURCHASE ORDERS ──────────────────────────────────────────────────────
    if ($action === 'purchase_orders') {
        $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
        try {
            $stmt = $pdo->query(
                "SELECT id, po_number, status, total_amount, type,
                        created_at, admin_finalized, delivery_validated, stock_in_done
                 FROM purchase_orders
                 WHERE $where
                 ORDER BY created_at DESC
                 LIMIT 50"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rows' => $rows]);
        } catch(Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── CUSTOMERS / AR ───────────────────────────────────────────────────────
    if ($action === 'customers') {
        $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
        try {
            $stmt = $pdo->query(
                "SELECT id, name, email, phone, credit_limit,
                        credit_balance, status, created_at
                 FROM customers
                 WHERE $where
                 ORDER BY name ASC
                 LIMIT 200"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rows' => $rows]);
        } catch(Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── CALENDAR EVENTS ──────────────────────────────────────────────────────
    if ($action === 'calendar') {
        $rows = [];
        $include_completed = !empty($_GET['all']) || ($_GET['include_completed'] ?? '') === '1';
        $status_condition = $include_completed ? "status != 'cancelled'" : "status NOT IN ('completed', 'cancelled', 'done')";

        try {
            // First check staff_calendar_events (standard schema)
            if ($role === 'superadmin') {
                $stmt = $pdo->query("
                    SELECT id, event_date, start_time, end_time, event_type, work_description, status, metadata
                    FROM staff_calendar_events
                    WHERE $status_condition AND event_date >= CURDATE()
                    ORDER BY event_date ASC, start_time ASC
                    LIMIT 50
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, event_date, start_time, end_time, event_type, work_description, status, metadata
                    FROM staff_calendar_events
                    WHERE $status_condition AND event_date >= CURDATE()
                      AND (station_id = ? OR station_id IS NULL OR station_id = 0)
                    ORDER BY event_date ASC, start_time ASC
                    LIMIT 50
                ");
                $stmt->execute([$station_id]);
            }
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e1) {
            // Fallback to legacy calendar_events table
            try {
                $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
                $stmt = $pdo->query("SELECT * FROM calendar_events WHERE $where AND event_date >= CURDATE() ORDER BY event_date ASC LIMIT 50");
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Exception $e2) {
                $rows = [];
            }
        }
        echo json_encode(['ok' => true, 'events' => $rows]);
        exit;
    }

    // ── APPROVALS / LOGS ─────────────────────────────────────────────────────
    if ($action === 'approvals') {
        $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
        $pending = [];
        try {
            $s = $pdo->query("SELECT id, type, status, created_at FROM purchase_orders WHERE $where AND status IN ('Pending','Pending Approval','Pending Admin Validation') ORDER BY created_at DESC LIMIT 20");
            $pending['purchase_orders'] = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e){}
        try {
            $s = $pdo->query("SELECT id, product_type, status, created_at FROM pending_price_approvals WHERE $where AND status='pending' ORDER BY created_at DESC LIMIT 20");
            $pending['price_approvals'] = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e){}
        echo json_encode(['ok' => true, 'pending' => $pending]);
        exit;
    }

    // ── STOCK REQUESTS ───────────────────────────────────────────────────────
    if ($action === 'stock_requests') {
        $where = $role === 'superadmin' ? '1=1' : "station_id = $station_id";
        try {
            $stmt = $pdo->query(
                "SELECT id, product_name, requested_quantity, status, requested_by, created_at
                 FROM stock_requests
                 WHERE $where
                 ORDER BY created_at DESC
                 LIMIT 50"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rows' => $rows]);
        } catch(Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── HEALTH CHECK ─────────────────────────────────────────────────────────
    if ($action === 'ping') {
        echo json_encode([
            'ok'         => true,
            'ts'         => time(),
            'station_id' => $station_id,
            'role'       => $role,
        ]);
        exit;
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Throwable $e) {
    error_log('api_refresh.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error. Please try again.']);
}
