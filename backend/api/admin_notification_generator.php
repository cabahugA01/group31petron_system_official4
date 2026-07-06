<?php
/**
 * Admin Notification Generator
 * backend/api/admin_notification_generator.php
 *
 * Scans real operational tables and inserts dynamic notifications into the
 * notifications table for the currently logged-in Admin user.
 *
 * Called via AJAX from the header on every page load for Admin role.
 * Uses source_key to prevent duplicate/stale notifications.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me  = current_user();
$role  = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

if ($role !== 'admin') {  echo json_encode(['ok' => false, 'error' => 'Admin only']); exit;
}

$station_id = (int)(user_station_id() ?? 0);

// ── Ensure notifications table exists ────────────────────────
try {  $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (  id  INT AUTO_INCREMENT PRIMARY KEY,  user_id  INT NOT NULL,  type  ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',  title  VARCHAR(255) NOT NULL DEFAULT '',  message  TEXT NOT NULL,  event_type  VARCHAR(80) NOT NULL DEFAULT 'general',  severity  ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',  source_key  VARCHAR(200) NULL,  redirect_url VARCHAR(500) NULL,  status  ENUM('unread','read') NOT NULL DEFAULT 'unread',  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,  read_at  TIMESTAMP NULL,  INDEX idx_user_status (user_id, status),  INDEX idx_event_type  (event_type),  INDEX idx_source_key  (source_key),  INDEX idx_created_at  (created_at)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$generated = 0;

/**
 * Upsert a notification:
 * - If source_key already exists for this user AND status='unread' -> skip (already notified)
 * - If source_key exists but status='read' AND message changed -> re-insert as unread
 * - If source_key doesn't exist -> insert
 */
function upsert_notif(PDO $pdo, int $user_id, array $data): int {  $key = $data['source_key'] ?? null;  if ($key) {  $existing = $pdo->prepare(  "SELECT id, status, message FROM notifications WHERE user_id=? AND source_key=? ORDER BY created_at DESC LIMIT 1"  );  $existing->execute([$user_id, $key]);  $row = $existing->fetch(PDO::FETCH_ASSOC);  if ($row) {  // Message matches and status is unread -> skip  if ($row['status'] === 'unread' && $row['message'] === $data['message']) return 0;  // Otherwise, update existing to unread with new message  $upd = $pdo->prepare(  "UPDATE notifications SET message=?, type=?, severity=?, status='unread', read_at=NULL, created_at=NOW()  WHERE id=?"  );  $upd->execute([$data['message'], $data['type'], $data['severity'], $row['id']]);  return 1;  }  }  $ins = $pdo->prepare(  "INSERT INTO notifications (user_id, type, title, message, event_type, severity, source_key, redirect_url, status)  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unread')"  );  $ins->execute([  $user_id,  $data['type']  ?? 'info',  $data['title']  ?? 'Alert',  $data['message']  ?? '',  $data['event_type']  ?? 'general',  $data['severity']  ?? 'medium',  $data['source_key']  ?? null,  $data['redirect_url'] ?? null,  ]);  return 1;
}

function adm_count(PDO $pdo, string $sql, array $p = []): int {  try {  $s = $pdo->prepare($sql);  $s->execute($p);  return (int)$s->fetchColumn();  } catch (Exception $e) {  return 0;  }
}

// ════════════════════════════════════════════════════════════
// 1. DELIVERIES AWAITING ADMIN OVERSIGHT
// ════════════════════════════════════════════════════════════
$pending_admin_del = adm_count($pdo,  "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Admin Oversight'",  [$station_id]);
if ($pending_admin_del > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'warning',  'title'  => 'Deliveries Awaiting Your Oversight',  'message'  => "{$pending_admin_del} delivery(ies) have been validated by Manager and are now awaiting your final oversight.",  'event_type'  => 'delivery',  'severity'  => 'high',  'source_key'  => "admin_del_oversight_{$station_id}",  'redirect_url'=> '/public/admin_deliveries_oversight.php',  ]);
}

// ── 1b. Flagged Deliveries ────────────────────────────────
$flagged_del = adm_count($pdo,  "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Flagged','Discrepancy')",  [$station_id]);
if ($flagged_del > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'error',  'title'  => 'Flagged Deliveries',  'message'  => "{$flagged_del} delivery(ies) flagged with discrepancies. Immediate review required.",  'event_type'  => 'delivery',  'severity'  => 'critical',  'source_key'  => "flagged_del_{$station_id}",  'redirect_url'=> '/public/admin_deliveries_oversight.php',  ]);
}

// ════════════════════════════════════════════════════════════
// 2. OFFICIAL TRANSACTIONS TODAY
// ════════════════════════════════════════════════════════════
$admin_tx = adm_count($pdo,  "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status IN ('Official','Completed','Approved','Adjusted') AND DATE(COALESCE(transaction_date,created_at))=CURDATE()",  [$station_id]);
if ($admin_tx > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'info',  'title'  => 'Official Transactions Today',  'message'  => "{$admin_tx} official transaction(s) are available for oversight review.",  'event_type'  => 'transaction',  'severity'  => 'low',  'source_key'  => "admin_tx_today_{$station_id}_".date('Y-m-d'),  'redirect_url'=> '/public/admin_transactions_oversight.php',  ]);
}

// ════════════════════════════════════════════════════════════
// 3. PENDING PURCHASE ORDERS
// ════════════════════════════════════════════════════════════
$pending_po = adm_count($pdo,  "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation')",  [$station_id]);
if ($pending_po > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'warning',  'title'  => 'Pending Purchase Orders',  'message'  => "{$pending_po} purchase order(s) awaiting admin validation.",  'event_type'  => 'delivery',  'severity'  => 'medium',  'source_key'  => "pending_po_{$station_id}",  'redirect_url'=> '/public/purchase_orders.php',  ]);
}

// ════════════════════════════════════════════════════════════
// 4. OFFICIAL JOB ORDERS TODAY
// ════════════════════════════════════════════════════════════
$admin_jo = adm_count($pdo,  "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND validation_status IN ('Official','Completed','Approved','Adjusted') AND DATE(created_at)=CURDATE()",  [$station_id]);
if ($admin_jo > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'info',  'title'  => 'Official Job Orders Today',  'message'  => "{$admin_jo} job order(s) are available for oversight review.",  'event_type'  => 'job_order',  'severity'  => 'low',  'source_key'  => "admin_jo_today_{$station_id}_".date('Y-m-d'),  'redirect_url'=> '/public/admin_transactions_oversight.php',  ]);
}

// ════════════════════════════════════════════════════════════
// 5. FUEL VARIANCE ALERTS
// ════════════════════════════════════════════════════════════
$variance_open = adm_count($pdo,  "SELECT COUNT(*) FROM variance_alerts WHERE station_id=? AND status='open'",  [$station_id]);
if ($variance_open > 0) {  $var_liters = 0;  try {  $vs = $pdo->prepare("SELECT COALESCE(SUM(ABS(variance_liters)),0) FROM fuel_variance_reports WHERE station_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");  $vs->execute([$station_id]);  $var_liters = (float)$vs->fetchColumn();  } catch (Exception $e) {}  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'error',  'title'  => 'Fuel Variance Alerts',  'message'  => "{$variance_open} open variance alert(s). Total discrepancy: ".number_format($var_liters,2)."L. Pump vs sales vs deliveries mismatch detected.",  'event_type'  => 'report',  'severity'  => 'critical',  'source_key'  => "variance_open_{$station_id}",  'redirect_url'=> '/public/admin_reports.php?tab=variance',  ]);
}

// ════════════════════════════════════════════════════════════
// 6. ACCOUNTS RECEIVABLE OUTSTANDING
// ════════════════════════════════════════════════════════════
$ar_overdue = adm_count($pdo,  "SELECT COUNT(*) FROM customers WHERE station_id=? AND COALESCE(current_balance, balance, 0) > 0",  [$station_id]);
if ($ar_overdue > 0) {  $ar_total = 0;  try {  $as = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(current_balance, balance, 0)),0) FROM customers WHERE station_id=? AND COALESCE(current_balance, balance, 0) > 0");  $as->execute([$station_id]);  $ar_total = (float)$as->fetchColumn();  } catch (Exception $e) {}  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'warning',  'title'  => 'Accounts Receivable Outstanding',  'message'  => "{$ar_overdue} customer(s) with outstanding balance. Total: &#8369;".number_format($ar_total,2).".",  'event_type'  => 'customer',  'severity'  => 'high',  'source_key'  => "ar_overdue_{$station_id}",  'redirect_url'=> '/public/admin_reports.php?tab=receivable',  ]);
}

// ════════════════════════════════════════════════════════════
// 7. STAFF SHIFT ISSUES (No Active Shifts Today)
// ════════════════════════════════════════════════════════════
$active_shifts = adm_count($pdo,  "SELECT COUNT(*) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE()",  [$station_id]);
if ($active_shifts === 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'info',  'title'  => 'No Active Shifts Today',  'message'  => "No staff shifts logged for today. Check attendance and scheduling.",  'event_type'  => 'general',  'severity'  => 'low',  'source_key'  => "no_shifts_".date('Y-m-d')."_{$station_id}",  'redirect_url'=> '/public/admin_staff_oversight.php',  ]);
}

// ════════════════════════════════════════════════════════════
// 8. MANAGER ACTIONS (24h)
// ════════════════════════════════════════════════════════════
try {  $mgr_actions = $pdo->prepare(  "SELECT COUNT(*) FROM audit_logs al  JOIN users u ON u.id = al.user_id  WHERE u.station_id=? AND LOWER(u.role) IN ('manager','supervisor')  AND al.action_type IN ('Approved','Rejected','Adjusted')  AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"  );  $mgr_actions->execute([$station_id]);  $mgr_cnt = (int)$mgr_actions->fetchColumn();  if ($mgr_cnt > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'info',  'title'  => 'Manager Activity (24h)',  'message'  => "{$mgr_cnt} manager action(s) in the last 24 hours (approvals, rejections, adjustments). Review audit trail.",  'event_type'  => 'report',  'severity'  => 'low',  'source_key'  => "mgr_actions_".date('Y-m-d')."_{$station_id}",  'redirect_url'=> '/public/admin_audit_trail.php',  ]);  }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 9. SUSPICIOUS AUDIT ACTIONS (48h)
// ════════════════════════════════════════════════════════════
try {  $suspicious = $pdo->prepare(  "SELECT COUNT(*) FROM audit_logs al  JOIN users u ON u.id = al.user_id  WHERE u.station_id=? AND al.action_type IN ('Delete','Bulk Delete','Override','Force Approve')  AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)"  );  $suspicious->execute([$station_id]);  $sus_cnt = (int)$suspicious->fetchColumn();  if ($sus_cnt > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'error',  'title'  => 'Suspicious Audit Actions Detected',  'message'  => "{$sus_cnt} unusual action(s) logged in the last 48 hours (deletions, overrides, force approvals). Review immediately.",  'event_type'  => 'report',  'severity'  => 'critical',  'source_key'  => "suspicious_audit_".date('Y-m-d')."_{$station_id}",  'redirect_url'=> '/public/admin_audit_trail.php',  ]);  }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 10. LOW INVENTORY
// ════════════════════════════════════════════════════════════
try {  $low_inv = $pdo->prepare(  "SELECT COUNT(*) FROM station_inventory si  INNER JOIN inventory_products ip ON ip.id = si.product_id  WHERE si.station_id = ?  AND si.stock_level >= 0  AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10)  AND LOWER(ip.category) NOT IN ('fuel', 'fuels')"  );  $low_inv->execute([$station_id]);  $low_cnt = (int)$low_inv->fetchColumn();  if ($low_cnt > 0) {  $generated += upsert_notif($pdo, $user_id, [  'type'  => 'warning',  'title'  => 'Low Inventory Alert',  'message'  => "{$low_cnt} product(s) at or below minimum stock level. Reorder required.",  'event_type'  => 'inventory',  'severity'  => 'high',  'source_key'  => "low_inv_{$station_id}",  'redirect_url'=> '/public/inventory.php',  ]);  }
} catch (Exception $e) {}

// ── Cleanup old read notifications (> 14 days) ─────────────────
try {  $stmt = $pdo->prepare(  "DELETE FROM notifications  WHERE user_id = ? AND status = 'read'  AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"  );  $stmt->execute([$user_id]);
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
