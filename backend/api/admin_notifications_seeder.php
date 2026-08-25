<?php
/**
 * Admin Notifications Seeder
 * backend/api/admin_notifications_seeder.php
 *
 * Generates real-time oversight alerts for admin/superadmin.
 * Called via AJAX on admin dashboard load.
 * Uses source_key to avoid duplicate inserts (upsert pattern).
 *
 * GET  ?action=seed   — generate/refresh alerts, return unread count
 * GET  ?action=list   — return latest 20 alerts for dropdown
 * POST ?action=mark_read  (notification_id)
 * POST ?action=mark_all_read
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me         = current_user();
$role       = role_key($me['role'] ?? '');
$user_id    = (int)($me['id'] ?? 0);
$station_id = (int)(user_station_id() ?? 0);

if (!in_array($role, ['admin', 'superadmin']) || $user_id === 0) {
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
        title        VARCHAR(255) NOT NULL DEFAULT '',
        message      TEXT NOT NULL,
        event_type   VARCHAR(80) NOT NULL DEFAULT 'general',
        severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
        source_key   VARCHAR(200) NULL,
        redirect_url VARCHAR(500) NULL,
        status       ENUM('unread','read') NOT NULL DEFAULT 'unread',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at      TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Migrate: safely add missing columns to older table schemas ─
// Fixes: "Unknown column 'source_key' in 'where clause'" on legacy tables
foreach ([
    'event_type'   => "ALTER TABLE notifications ADD COLUMN event_type   VARCHAR(80)   NOT NULL DEFAULT 'general' AFTER message",
    'severity'     => "ALTER TABLE notifications ADD COLUMN severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER event_type",
    'source_key'   => "ALTER TABLE notifications ADD COLUMN source_key   VARCHAR(200)  NULL AFTER severity",
    'redirect_url' => "ALTER TABLE notifications ADD COLUMN redirect_url VARCHAR(500)  NULL AFTER source_key",
    'read_at'      => "ALTER TABLE notifications ADD COLUMN read_at      TIMESTAMP     NULL AFTER created_at",
] as $col => $alter_sql) {
    try {
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = ?"
        );
        $chk->execute([$col]);
        if (!(bool)$chk->fetchColumn()) {
            $pdo->exec($alter_sql);
        }
    } catch (Exception $e) {}
}
// Ensure indexes exist (silently ignore if already present)
foreach ([
    "CREATE INDEX IF NOT EXISTS idx_notif_user_status ON notifications (user_id, status)",
    "CREATE INDEX IF NOT EXISTS idx_notif_source_key  ON notifications (source_key(100))",
    "CREATE INDEX IF NOT EXISTS idx_notif_created_at  ON notifications (created_at)",
] as $idx_sql) {
    try { $pdo->exec($idx_sql); } catch (Exception $e) {}
}

// ── Helpers ───────────────────────────────────────────────────
function adm_count(PDO $pdo, string $sql, array $p = []): int {
    try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

/**
 * Upsert a notification:
 * - If source_key already exists for this user AND status='unread' → skip (already notified)
 * - If source_key exists but status='read' AND count changed → re-insert as unread
 * - If source_key doesn't exist → insert
 */
function upsert_notif(PDO $pdo, int $user_id, array $data): void {
    $key = $data['source_key'] ?? null;
    if ($key) {
        // Check if already unread with same key
        $existing = $pdo->prepare(
            "SELECT id, status, message FROM notifications WHERE user_id=? AND source_key=? ORDER BY created_at DESC LIMIT 1"
        );
        $existing->execute([$user_id, $key]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // If unread and message unchanged → skip
            if ($row['status'] === 'unread' && $row['message'] === $data['message']) return;
            // If message changed (count updated) → update existing row
            $upd = $pdo->prepare(
                "UPDATE notifications SET message=?, type=?, severity=?, status='unread', read_at=NULL, created_at=NOW()
                 WHERE id=?"
            );
            $upd->execute([$data['message'], $data['type'], $data['severity'], $row['id']]);
            return;
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO notifications (user_id, recipient_role, type, title, message, event_type, severity, source_key, redirect_url, status)
         VALUES (?, 'admin', ?, ?, ?, ?, ?, ?, ?, 'unread')"
    );
    $ins->execute([
        $user_id,
        $data['type']        ?? 'info',
        $data['title']       ?? 'Alert',
        $data['message']     ?? '',
        $data['event_type']  ?? 'general',
        $data['severity']    ?? 'medium',
        $data['source_key']  ?? null,
        $data['redirect_url'] ?? null,
    ]);
}

function time_ago_adm(string $dt): string {
    $diff = max(0, time() - strtotime($dt));
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('M j', strtotime($dt));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'seed';

// ══════════════════════════════════════════════════════════════
// SEED ACTION — generate/refresh all admin oversight alerts
// ══════════════════════════════════════════════════════════════
if ($action === 'seed') {

    // ── 1. Deliveries Awaiting Admin Oversight ───────────────
    $pending_admin_del = adm_count($pdo,
        "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Admin Oversight'",
        [$station_id]);
    if ($pending_admin_del > 0) {
        upsert_notif($pdo, $user_id, [
            'type'        => 'warning',
            'title'       => 'Deliveries Awaiting Your Oversight',
            'message'     => "{$pending_admin_del} delivery(ies) have been validated by Manager and are now awaiting your final oversight.",
            'event_type'  => 'delivery',
            'severity'    => 'high',
            'source_key'  => "admin_del_oversight_{$station_id}",
            'redirect_url'=> 'admin_deliveries_oversight.php',
        ]);
    }

    // ── 1b. Flagged Deliveries ────────────────────────────────
    $flagged_del = adm_count($pdo,
        "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Flagged','Discrepancy')",
        [$station_id]);
    if ($flagged_del > 0) {
        upsert_notif($pdo, $user_id, [
            'type'        => 'error',
            'title'       => 'Flagged Deliveries',
            'message'     => "{$flagged_del} delivery(ies) flagged with discrepancies. Immediate review required.",
            'event_type'  => 'delivery',
            'severity'    => 'critical',
            'source_key'  => "flagged_del_{$station_id}",
            'redirect_url'=> 'admin_deliveries_oversight.php',
        ]);
    }

    // ── 3. Pending Purchase Orders ────────────────────────────
    $pending_po = adm_count($pdo,
        "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation')",
        [$station_id]);
    if ($pending_po > 0) {
        upsert_notif($pdo, $user_id, [
            'type'        => 'warning',
            'title'       => 'Pending Purchase Orders',
            'message'     => "{$pending_po} purchase order(s) awaiting admin validation.",
            'event_type'  => 'delivery',
            'severity'    => 'medium',
            'source_key'  => "pending_po_{$station_id}",
            'redirect_url'=> 'admin_procurement_reports.php?section=po',
        ]);
    }

    // ── 5. Variance Alerts ────────────────────────────────────
    $variance_open = adm_count($pdo,
        "SELECT COUNT(*) FROM variance_alerts WHERE station_id=? AND status='open'",
        [$station_id]);
    if ($variance_open > 0) {
        $var_liters = 0;
        try {
            $vs = $pdo->prepare("SELECT COALESCE(SUM(ABS(variance_liters)),0) FROM fuel_variance_reports WHERE station_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
            $vs->execute([$station_id]);
            $var_liters = (float)$vs->fetchColumn();
        } catch (Exception $e) {}
        upsert_notif($pdo, $user_id, [
            'type'        => 'error',
            'title'       => 'Fuel Variance Alerts',
            'message'     => "{$variance_open} open variance alert(s). Total discrepancy: ".number_format($var_liters,2)."L. Pump vs sales vs deliveries mismatch detected.",
            'event_type'  => 'report',
            'severity'    => 'critical',
            'source_key'  => "variance_open_{$station_id}",
            'redirect_url'=> 'admin_reports.php?tab=variance',
        ]);
    }

    // ── 6. Accounts Receivable Outstanding ───────────────────
    $ar_overdue = adm_count($pdo,
        "SELECT COUNT(*) FROM customers WHERE station_id=? AND COALESCE(current_balance, balance, 0) > 0",
        [$station_id]);
    if ($ar_overdue > 0) {
        $ar_total = 0;
        try {
            $as = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(current_balance, balance, 0)),0) FROM customers WHERE station_id=? AND COALESCE(current_balance, balance, 0) > 0");
            $as->execute([$station_id]);
            $ar_total = (float)$as->fetchColumn();
        } catch (Exception $e) {}
        upsert_notif($pdo, $user_id, [
            'type'        => 'warning',
            'title'       => 'Accounts Receivable Outstanding',
            'message'     => "{$ar_overdue} customer(s) with outstanding balance. Total: &#8369;".number_format($ar_total,2).".",
            'event_type'  => 'customer',
            'severity'    => 'high',
            'source_key'  => "ar_overdue_{$station_id}",
            'redirect_url'=> 'admin_reports.php?tab=receivable',
        ]);
    }

    // ── 7. Staff Shift Issues (no active shifts today) ────────
    $active_shifts = adm_count($pdo,
        "SELECT COUNT(*) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE()",
        [$station_id]);
    $staff_audit_today = adm_count($pdo,
        "SELECT COUNT(*) FROM audit_logs al JOIN users u ON u.id=al.user_id WHERE u.station_id=? AND LOWER(u.role)='staff' AND DATE(al.created_at)=CURDATE()",
        [$station_id]);
    $txn_today = adm_count($pdo,
        "SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE()",
        [$station_id]);
    $merch_today = adm_count($pdo,
        "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE()",
        [$station_id]);

    $has_staff_act = ($active_shifts > 0) || ($staff_audit_today > 0) || ($txn_today > 0) || ($merch_today > 0);

    if (!$has_staff_act) {
        upsert_notif($pdo, $user_id, [
            'type'        => 'info',
            'title'       => 'No Active Shifts Today',
            'message'     => "No staff shifts logged for today. Check attendance and scheduling.",
            'event_type'  => 'general',
            'severity'    => 'low',
            'source_key'  => "no_shifts_".date('Y-m-d')."_{$station_id}",
            'redirect_url'=> 'users.php',
        ]);
    } else {
        try {
            $del = $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND (source_key = ? OR (title = 'No Active Shifts Today' AND DATE(created_at) = CURDATE()))");
            $del->execute([$user_id, "no_shifts_".date('Y-m-d')."_{$station_id}"]);
        } catch (Exception $e) {}
    }

    // ── 8. Manager Actions (recent approvals/rejections) ──────
    try {
        $mgr_actions = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs al
             JOIN users u ON u.id = al.user_id
             WHERE u.station_id=? AND LOWER(u.role) IN ('manager','supervisor')
             AND al.action_type IN ('Approved','Rejected','Adjusted')
             AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $mgr_actions->execute([$station_id]);
        $mgr_cnt = (int)$mgr_actions->fetchColumn();
        if ($mgr_cnt > 0) {
            upsert_notif($pdo, $user_id, [
                'type'        => 'info',
                'title'       => 'Manager Activity (24h)',
                'message'     => "{$mgr_cnt} manager action(s) in the last 24 hours (approvals, rejections, adjustments). Review audit trail.",
                'event_type'  => 'report',
                'severity'    => 'low',
                'source_key'  => "mgr_actions_".date('Y-m-d')."_{$station_id}",
                'redirect_url'=> 'admin_audit_trail.php',
            ]);
        }
    } catch (Exception $e) {}

    // ── 9. Suspicious Audit Actions ───────────────────────────
    try {
        $suspicious = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs al
             JOIN users u ON u.id = al.user_id
             WHERE u.station_id=? AND al.action_type IN ('Delete','Bulk Delete','Override','Force Approve')
             AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );
        $suspicious->execute([$station_id]);
        $sus_cnt = (int)$suspicious->fetchColumn();
        if ($sus_cnt > 0) {
            upsert_notif($pdo, $user_id, [
                'type'        => 'error',
                'title'       => 'Suspicious Audit Actions Detected',
                'message'     => "{$sus_cnt} unusual action(s) logged in the last 48 hours (deletions, overrides, force approvals). Review immediately.",
                'event_type'  => 'report',
                'severity'    => 'critical',
                'source_key'  => "suspicious_audit_".date('Y-m-d')."_{$station_id}",
                'redirect_url'=> 'admin_audit_trail.php',
            ]);
        }
    } catch (Exception $e) {}

    // ── 10. Low Inventory ─────────────────────────────────────
    try {
        $low_inv = $pdo->prepare(
            "SELECT COUNT(*) FROM station_inventory si INNER JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id=? AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10) AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels')"
        );
        $low_inv->execute([$station_id]);
        $low_cnt = (int)$low_inv->fetchColumn();
        if ($low_cnt > 0) {
            upsert_notif($pdo, $user_id, [
                'type'        => 'warning',
                'title'       => 'Low Inventory Alert',
                'message'     => "{$low_cnt} product(s) at or below minimum stock level. Reorder required.",
                'event_type'  => 'inventory',
                'severity'    => 'high',
                'source_key'  => "low_inv_{$station_id}",
                'redirect_url'=> 'admin_inventory_merchandise.php',
            ]);
        }
    } catch (Exception $e) {}

    // Return unread count after seeding
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
    $cnt_stmt->execute([$user_id]);
    $unread = (int)$cnt_stmt->fetchColumn();

    echo json_encode(['success' => true, 'unread_count' => $unread]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// LIST ACTION — return latest notifications for dropdown
// ══════════════════════════════════════════════════════════════
if ($action === 'list') {
    $limit  = min((int)($_GET['limit'] ?? 20), 50);
    $offset = (int)($_GET['offset'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
         FROM notifications
         WHERE user_id=?
         ORDER BY created_at DESC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$n) {
        $n['time_ago']  = time_ago_adm($n['created_at']);
        $n['is_unread'] = ($n['status'] === 'unread');
    }
    unset($n);

    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
    $cnt_stmt->execute([$user_id]);
    $unread = (int)$cnt_stmt->fetchColumn();

    echo json_encode([
        'success'       => true,
        'notifications' => $rows,
        'unread_count'  => $unread,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// MARK READ / MARK ALL READ
// ══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    if ($action === 'mark_read') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            $pdo->prepare("UPDATE notifications SET status='read', read_at=NOW() WHERE id=? AND user_id=?")->execute([$nid, $user_id]);
        }
    } elseif ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET status='read', read_at=NOW() WHERE user_id=? AND status='unread'")->execute([$user_id]);
    }
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND status='unread'");
    $cnt_stmt->execute([$user_id]);
    echo json_encode(['success' => true, 'unread_count' => (int)$cnt_stmt->fetchColumn()]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
