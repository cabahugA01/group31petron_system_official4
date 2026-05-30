<?php
/**
 * Admin Reports & Audit Trail API — schema-verified queries.
 * Column names confirmed against actual DB schema and working export handlers.
 *
 * Key schema facts:
 *  fuel_transactions   : NO status column; transaction_id (VARCHAR PK alias), liters_sold, total_amount
 *  merchandise_transactions: id, staff_id, total_amount, payment_method, validation_status, created_at
 *  job_orders          : created_by (+ user_id fallback), service_description, estimated_cost, assigned_mechanic_id
 *  customers           : credit_balance (or balance — detected at runtime), credit_limit
 *  deliveries_oversight: supplier, product, quantity, delivery_date, encoded_by, status, remarks
 *  audit_logs          : user_id, action_type, entity_type, action_details, ip_address, status, created_at
 */
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Access denied. Admin only.']);
    exit;
}

$action    = trim($_GET['action'] ?? '');
$date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
$format    = trim($_GET['format']    ?? 'csv');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ── Helpers ───────────────────────────────────────────────────────────────────
function api_ok($data) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function api_err($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function safe_rows(PDO $pdo, string $sql, array $p = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log('admin_reports_audit_api safe_rows: ' . $e->getMessage());
        return [];
    }
}
function safe_val(PDO $pdo, string $sql, array $p = [], $default = 0) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn() ?? $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Station name
$station_name = 'Station #' . $station_id;
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

// ── Schema detection (run once, used across cases) ────────────────────────────
// fuel_variance_reports
$has_fvr = false;
try { $pdo->query("SELECT 1 FROM fuel_variance_reports LIMIT 1"); $has_fvr = true; } catch (Exception $e) {}

// customers balance column
$bal_col = 'balance';
try { $pdo->query("SELECT credit_balance FROM customers LIMIT 0"); $bal_col = 'credit_balance'; } catch (Exception $e) {}

// accounts_receivable table
$has_ar = false;
try { $pdo->query("SELECT 1 FROM accounts_receivable LIMIT 1"); $has_ar = true; } catch (Exception $e) {}

// job_orders.user_id fallback
$jo_has_uid = false;
try { $pdo->query("SELECT user_id FROM job_orders LIMIT 0"); $jo_has_uid = true; } catch (Exception $e) {}
$jo_user_expr = $jo_has_uid ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.created_by';

// merchandise_transactions.transaction_date
$mt_has_txn_date = false;
try { $pdo->query("SELECT transaction_date FROM merchandise_transactions LIMIT 0"); $mt_has_txn_date = true; } catch (Exception $e) {}
$mt_date = $mt_has_txn_date
    ? "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END"
    : "DATE(mt.created_at)";

// deliveries_oversight
$has_del = false;
try { $pdo->query("SELECT 1 FROM deliveries_oversight LIMIT 1"); $has_del = true; } catch (Exception $e) {}

// labor_sessions (for staff performance)
$has_ls = false;
try { $pdo->query("SELECT 1 FROM labor_sessions LIMIT 1"); $has_ls = true; } catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════════════════════
// SWITCH
// ══════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── SALES: FUEL ──────────────────────────────────────────────────────────
    // fuel_transactions has NO status column — show all records, join variance
    case 'sales_fuel':
        if ($has_fvr) {
            $rows = safe_rows($pdo, "
                SELECT DATE(ft.transaction_date)              AS sale_date,
                       ft.fuel_type,
                       COUNT(ft.transaction_id)               AS txn_count,
                       COALESCE(SUM(ft.liters_sold), 0)       AS total_liters,
                       COALESCE(SUM(ft.total_amount), 0)      AS total_revenue,
                       COALESCE(AVG(fvr.variance_liters), 0)  AS avg_variance
                FROM fuel_transactions ft
                LEFT JOIN fuel_variance_reports fvr
                    ON  fvr.station_id = ft.station_id
                    AND DATE(fvr.report_date) = DATE(ft.transaction_date)
                    AND LOWER(TRIM(fvr.fuel_type)) = LOWER(TRIM(ft.fuel_type))
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(ft.transaction_date), ft.fuel_type
                ORDER BY sale_date DESC, ft.fuel_type
            ", [$station_id, $date_from, $date_to]);
        } else {
            $rows = safe_rows($pdo, "
                SELECT DATE(ft.transaction_date)         AS sale_date,
                       ft.fuel_type,
                       COUNT(ft.transaction_id)          AS txn_count,
                       COALESCE(SUM(ft.liters_sold), 0)  AS total_liters,
                       COALESCE(SUM(ft.total_amount), 0) AS total_revenue,
                       0                                 AS avg_variance
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(ft.transaction_date), ft.fuel_type
                ORDER BY sale_date DESC, ft.fuel_type
            ", [$station_id, $date_from, $date_to]);
        }
        api_ok($rows);

    // ── SALES: MERCHANDISE ───────────────────────────────────────────────────
    case 'sales_merch':
        $rows = safe_rows($pdo, "
            SELECT ($mt_date)                                                                                                  AS sale_date,
                   COUNT(mt.id)                                                                                                AS txn_count,
                   COALESCE(SUM(mt.total_amount), 0)                                                                          AS total_revenue,
                   COALESCE(SUM(CASE WHEN LOWER(mt.payment_method) IN ('cash')                                                THEN mt.total_amount ELSE 0 END), 0) AS pay_cash,
                   COALESCE(SUM(CASE WHEN LOWER(mt.payment_method) IN ('credit card','card','debit card')                     THEN mt.total_amount ELSE 0 END), 0) AS pay_card,
                   COALESCE(SUM(CASE WHEN LOWER(mt.payment_method) IN ('gcash','maya','paymaya','e-wallet','ewallet')         THEN mt.total_amount ELSE 0 END), 0) AS pay_ewallet,
                   COALESCE(SUM(CASE WHEN LOWER(mt.payment_method) IN ('e-fuel card','fuel card','efuel')                     THEN mt.total_amount ELSE 0 END), 0) AS pay_efuel,
                   COALESCE(SUM(CASE WHEN LOWER(mt.payment_method) IN ('account receivable','credit','utang')                 THEN mt.total_amount ELSE 0 END), 0) AS pay_credit,
                   mt.validation_status
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND ($mt_date) BETWEEN ? AND ?
              AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('rejected', 'cancelled')
            GROUP BY ($mt_date), mt.validation_status
            ORDER BY sale_date DESC
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── SALES: DAILY SUMMARY ─────────────────────────────────────────────────
    case 'sales_daily_summary':
        if ($has_fvr) {
            $rows = safe_rows($pdo, "
                SELECT d.sale_date,
                       COALESCE(f.fuel_liters, 0)  AS total_fuel_liters,
                       COALESCE(f.fuel_rev, 0)     AS fuel_revenue,
                       COALESCE(m.merch_rev, 0)    AS merch_revenue,
                       COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) AS total_revenue,
                       COALESCE(f.avg_variance, 0) AS fuel_variance
                FROM (
                    SELECT DISTINCT DATE(ft.transaction_date) AS sale_date
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                    UNION
                    SELECT DISTINCT ($mt_date)
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND ($mt_date) BETWEEN ? AND ?
                      AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('rejected', 'cancelled')
                ) d
                LEFT JOIN (
                    SELECT DATE(ft.transaction_date)             AS sd,
                           COALESCE(SUM(ft.liters_sold), 0)      AS fuel_liters,
                           COALESCE(SUM(ft.total_amount), 0)     AS fuel_rev,
                           COALESCE(AVG(fvr.variance_liters), 0) AS avg_variance
                    FROM fuel_transactions ft
                    LEFT JOIN fuel_variance_reports fvr
                        ON fvr.station_id = ft.station_id
                        AND DATE(fvr.report_date) = DATE(ft.transaction_date)
                        AND LOWER(TRIM(fvr.fuel_type)) = LOWER(TRIM(ft.fuel_type))
                    WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                    GROUP BY DATE(ft.transaction_date)
                ) f ON f.sd = d.sale_date
                LEFT JOIN (
                    SELECT ($mt_date) AS sd, COALESCE(SUM(total_amount), 0) AS merch_rev
                    FROM merchandise_transactions
                    WHERE station_id = ? AND ($mt_date) BETWEEN ? AND ?
                      AND LOWER(COALESCE(validation_status, '')) NOT IN ('rejected', 'cancelled')
                    GROUP BY ($mt_date)
                ) m ON m.sd = d.sale_date
                ORDER BY d.sale_date DESC
            ", [
                $station_id, $date_from, $date_to,
                $station_id, $date_from, $date_to,
                $station_id, $date_from, $date_to,
                $station_id, $date_from, $date_to,
            ]);
        } else {
            $rows = safe_rows($pdo, "
                SELECT d.sale_date,
                       COALESCE(f.fuel_liters, 0) AS total_fuel_liters,
                       COALESCE(f.fuel_rev, 0)    AS fuel_revenue,
                       COALESCE(m.merch_rev, 0)   AS merch_revenue,
                       COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) AS total_revenue,
                       0 AS fuel_variance
                FROM (
                    SELECT DISTINCT DATE(ft.transaction_date) AS sale_date
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                    UNION
                    SELECT DISTINCT ($mt_date)
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND ($mt_date) BETWEEN ? AND ?
                      AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('rejected', 'cancelled')
                ) d
                LEFT JOIN (
                    SELECT DATE(ft.transaction_date)         AS sd,
                           COALESCE(SUM(ft.liters_sold), 0)  AS fuel_liters,
                           COALESCE(SUM(ft.total_amount), 0) AS fuel_rev
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                    GROUP BY DATE(ft.transaction_date)
                ) f ON f.sd = d.sale_date
                LEFT JOIN (
                    SELECT ($mt_date) AS sd, COALESCE(SUM(total_amount), 0) AS merch_rev
                    FROM merchandise_transactions
                    WHERE station_id = ? AND ($mt_date) BETWEEN ? AND ?
                      AND LOWER(COALESCE(validation_status, '')) NOT IN ('rejected', 'cancelled')
                    GROUP BY ($mt_date)
                ) m ON m.sd = d.sale_date
                ORDER BY d.sale_date DESC
            ", [
                $station_id, $date_from, $date_to,
                $station_id, $date_from, $date_to,
                $station_id, $date_from, $date_to,
                $station_id, $date_from, $date_to,
            ]);
        }
        api_ok($rows);

    // ── JOB ORDERS ───────────────────────────────────────────────────────────
    // Uses: created_by (+ user_id fallback), service_description, estimated_cost,
    //       assigned_mechanic_id -> mechanics table (not users)
    case 'job_orders':
        $rows = safe_rows($pdo, "
            SELECT jo.id,
                   COALESCE(c.name, jo.customer_name, 'Walk-in')  AS customer_name,
                   COALESCE(jo.service_type, '')                   AS service_type,
                   COALESCE(jo.service_description, '')            AS description,
                   jo.status,
                   jo.validation_status,
                   COALESCE(jo.actual_cost, jo.estimated_cost, 0)  AS cost,
                   COALESCE(us.name, '')                           AS staff_name,
                   COALESCE(mech.full_name, '')                    AS mechanic_name,
                   DATE(jo.created_at)                             AS order_date
            FROM job_orders jo
            LEFT JOIN customers c    ON c.id    = jo.customer_id
            LEFT JOIN users us       ON us.id   = $jo_user_expr
            LEFT JOIN mechanics mech ON mech.id = jo.assigned_mechanic_id
            WHERE jo.station_id = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
            LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── CUSTOMER BALANCES ────────────────────────────────────────────────────
    // credit_balance column detected at runtime; accounts_receivable for due date
    case 'customer_balances':
        $ar_join   = $has_ar ? "LEFT JOIN (SELECT customer_id, MIN(due_date) AS due_date FROM accounts_receivable WHERE station_id = ? AND status IN ('Pending','Active') GROUP BY customer_id) ar ON ar.customer_id = c.id" : "";
        $ar_due    = $has_ar ? "ar.due_date" : "NULL";
        $ar_params = $has_ar ? [$station_id, $station_id] : [$station_id];

        $rows = safe_rows($pdo, "
            SELECT c.id,
                   c.name,
                   COALESCE(c.{$bal_col}, 0)                                                                                AS outstanding_balance,
                   COALESCE(c.credit_limit, 0)                                                                              AS credit_limit,
                   CASE WHEN COALESCE(c.credit_limit, 0) > 0
                        THEN ROUND((COALESCE(c.{$bal_col}, 0) / c.credit_limit) * 100, 1)
                        ELSE 0 END                                                                                          AS usage_pct,
                   {$ar_due}                                                                                                AS due_date,
                   CASE WHEN COALESCE(c.{$bal_col}, 0) = 0                                                                 THEN 'Settled'
                        WHEN COALESCE(c.{$bal_col}, 0) > COALESCE(c.credit_limit, 0) AND COALESCE(c.credit_limit, 0) > 0  THEN 'Over Limit'
                        WHEN {$ar_due} IS NOT NULL AND {$ar_due} < CURDATE()                                               THEN 'Overdue'
                        ELSE 'Active' END                                                                                   AS status,
                   COALESCE(c.notes, c.mgr_notes, c.remarks, '')                                                           AS remarks
            FROM customers c
            $ar_join
            WHERE c.station_id = ?
              AND COALESCE(c.{$bal_col}, 0) > 0
            ORDER BY outstanding_balance DESC
            LIMIT 500
        ", $ar_params);
        api_ok($rows);

    // ── DELIVERIES ───────────────────────────────────────────────────────────
    case 'deliveries':
        if (!$has_del) { api_ok([]); }
        $rows = safe_rows($pdo, "
            SELECT d.id,
                   COALESCE(d.supplier, d.supplier_name, '')                    AS supplier,
                   COALESCE(d.product, d.product_name, '')                      AS product,
                   COALESCE(d.quantity, 0)                                      AS quantity,
                   COALESCE(d.unit, '')                                         AS unit,
                   DATE(COALESCE(d.delivery_date, d.created_at))                AS delivery_date,
                   COALESCE(ue.name, '')                                        AS encoder_name,
                   COALESCE(d.status, '')                                       AS status,
                   COALESCE(d.remarks, d.admin_notes, '')                       AS remarks
            FROM deliveries_oversight d
            LEFT JOIN users ue ON ue.id = d.encoded_by
            WHERE d.station_id = ?
              AND DATE(COALESCE(d.delivery_date, d.created_at)) BETWEEN ? AND ?
            ORDER BY delivery_date DESC
            LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── STAFF PERFORMANCE ────────────────────────────────────────────────────
    // Uses confirmed columns from _export_staff_handler.php
    case 'staff_performance':
        $del_join  = $has_del ? "LEFT JOIN deliveries_oversight del ON del.encoded_by = u.id AND del.station_id = ? AND DATE(del.delivery_date) BETWEEN ? AND ?" : "";
        $del_count = $has_del ? "COUNT(DISTINCT del.id) AS deliveries," : "0 AS deliveries,";
        $ls_join   = $has_ls  ? "LEFT JOIN labor_sessions ls ON ls.user_id = u.id AND ls.station_id = ? AND DATE(ls.start_time) BETWEEN ? AND ?" : "";
        $ls_count  = $has_ls  ? "COUNT(DISTINCT ls.id) AS shift_count, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW()))), 0) AS total_minutes," : "0 AS shift_count, 0 AS total_minutes,";

        $params = [
            $station_id, $date_from, $date_to,   // fuel_transactions
            $station_id, $date_from, $date_to,   // merchandise_transactions
            $station_id, $date_from, $date_to,   // job_orders
        ];
        if ($has_del) { $params[] = $station_id; $params[] = $date_from; $params[] = $date_to; }
        if ($has_ls)  { $params[] = $station_id; $params[] = $date_from; $params[] = $date_to; }
        $params[] = $station_id; // WHERE u.station_id

        $rows = safe_rows($pdo, "
            SELECT u.id,
                   u.name,
                   u.role,
                   COUNT(DISTINCT ft.transaction_id)  AS fuel_txn_count,
                   COUNT(DISTINCT mt.id)              AS merch_txn_count,
                   COUNT(DISTINCT jo.id)              AS job_orders,
                   $del_count
                   $ls_count
                   (COUNT(DISTINCT ft.transaction_id) + COUNT(DISTINCT mt.id) + COUNT(DISTINCT jo.id)) AS total_activity
            FROM users u
            LEFT JOIN fuel_transactions ft
                ON ft.staff_id = u.id AND ft.station_id = ?
                AND DATE(ft.transaction_date) BETWEEN ? AND ?
            LEFT JOIN merchandise_transactions mt
                ON mt.staff_id = u.id AND mt.station_id = ?
                AND DATE(mt.created_at) BETWEEN ? AND ?
            LEFT JOIN job_orders jo
                ON ($jo_user_expr = u.id) AND jo.station_id = ?
                AND DATE(jo.created_at) BETWEEN ? AND ?
            $del_join
            $ls_join
            WHERE u.station_id = ?
              AND u.status = 'active'
              AND LOWER(TRIM(u.role)) NOT IN ('admin', 'superadmin', 'super admin', 'super_admin')
            GROUP BY u.id, u.name, u.role
            ORDER BY total_activity DESC, u.name ASC
        ", $params);
        api_ok($rows);

    // ── AUDIT TRAIL ──────────────────────────────────────────────────────────
    // Admin = oversight only. Staff and Manager actions only.
    // audit_logs columns: user_id, action_type, entity_type, action_details,
    //                     ip_address, status, created_at  (all lowercase)
    case 'audit_trail':
        $user_filter   = (int)($_GET['user_id']   ?? 0);
        $action_filter = trim($_GET['action_type'] ?? '');
        $module_filter = trim($_GET['module']      ?? '');

        $sql = "SELECT al.id,
                       al.created_at,
                       u.name        AS user_name,
                       u.id          AS user_id,
                       u.role,
                       al.action_type,
                       al.entity_type AS module,
                       al.action_details AS details,
                       al.ip_address,
                       al.status
                FROM audit_logs al
                INNER JOIN users u ON u.id = al.user_id
                WHERE u.station_id = ?
                  AND DATE(al.created_at) BETWEEN ? AND ?
                  AND LOWER(TRIM(u.role)) NOT IN ('admin','superadmin','super admin','super_admin')";
        $params = [$station_id, $date_from, $date_to];

        if ($user_filter)   { $sql .= " AND al.user_id = ?";     $params[] = $user_filter; }
        if ($action_filter) { $sql .= " AND al.action_type = ?"; $params[] = $action_filter; }
        if ($module_filter) { $sql .= " AND al.entity_type = ?"; $params[] = $module_filter; }
        $sql .= " ORDER BY al.created_at DESC LIMIT 500";

        $rows = safe_rows($pdo, $sql, $params);
        api_ok($rows);

    // ── VARIANCE REPORTS ─────────────────────────────────────────────────────
    case 'variance_reports':
        if (!$has_fvr) { api_ok([]); }
        $rows = safe_rows($pdo, "
            SELECT fvr.id,
                   DATE(fvr.report_date) AS report_date,
                   fvr.fuel_type,
                   fvr.expected_stock,
                   fvr.actual_stock,
                   fvr.variance_liters,
                   fvr.variance_percent,
                   COALESCE(fvr.reason, '—') AS reason,
                   fvr.status
            FROM fuel_variance_reports fvr
            WHERE fvr.station_id = ?
              AND DATE(fvr.report_date) BETWEEN ? AND ?
            ORDER BY fvr.report_date DESC
            LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── ACCOUNTS RECEIVABLE ──────────────────────────────────────────────────
    case 'accounts_receivable':
        if (!$has_ar) { api_ok([]); }
        $rows = safe_rows($pdo, "
            SELECT ar.id,
                   ar.transaction_id,
                   COALESCE(c.name, 'Unknown Customer') AS customer_name,
                   ar.fuel_type AS type_details,
                   ar.amount,
                   ar.status,
                   ar.due_date,
                   DATE(ar.created_at) AS created_date
            FROM accounts_receivable ar
            LEFT JOIN customers c ON c.id = ar.customer_id
            WHERE ar.station_id = ?
              AND DATE(ar.created_at) BETWEEN ? AND ?
            ORDER BY ar.created_at DESC
            LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ══════════════════════════════════════════════════════════════════════════
    // EXPORTS — delegate to existing verified handlers
    // ══════════════════════════════════════════════════════════════════════════
    case 'export_variance':
        $rows = safe_rows($pdo, "
            SELECT DATE(report_date) AS report_date,
                   fuel_type, expected_stock, actual_stock,
                   variance_liters, variance_percent, reason, status
            FROM fuel_variance_reports
            WHERE station_id = ?
              AND DATE(report_date) BETWEEN ? AND ?
            ORDER BY report_date DESC
        ", [$station_id, $date_from, $date_to]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $v = (float)($r['variance_liters'] ?? 0);
                $vColor = $v > 0 ? '#dc2626' : ($v < 0 ? '#d97706' : '#16a34a');
                $vStr = ($v > 0 ? '+' : '') . number_format($v, 2) . ' L';
                $tbody .= '<tr>
                    <td>' . htmlspecialchars($r['report_date'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['fuel_type'] ?? '') . '</td>
                    <td style="text-align:right">' . number_format((float)($r['expected_stock'] ?? 0), 2) . ' L</td>
                    <td style="text-align:right">' . number_format((float)($r['actual_stock'] ?? 0), 2) . ' L</td>
                    <td style="text-align:right;color:' . $vColor . ';font-weight:bold">' . $vStr . '</td>
                    <td style="text-align:right">' . number_format((float)($r['variance_percent'] ?? 0), 2) . '%</td>
                    <td>' . htmlspecialchars($r['reason'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['status'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Variance Report</title>
            <style>
            body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
            .hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
            .hdr h1{font-size:18px;color:#00264D;margin-bottom:4px}
            .hdr p{font-size:10px;color:#64748b;margin-top:2px}
            table{width:100%;border-collapse:collapse}
            th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
            td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
            tr:nth-child(even) td{background:#f8fafc}
            .pbtn{margin-bottom:14px}
            .pbtn button{background:#00264D;color:#fff;border:none;padding:7px 18px;border-radius:5px;font-size:12px;cursor:pointer}
            @media print{.pbtn{display:none}body{padding:0}}
            </style></head><body>
            <div class="pbtn"><button onclick="window.print()">Print / Save as PDF</button></div>
            <div class="hdr">
              <h1>Fuel Variance Report</h1>
              <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
              <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
            </div>
            <table><thead><tr>
              <th>Date</th><th>Fuel Type</th><th>Expected Stock</th><th>Actual Stock</th><th>Variance (L)</th><th>Variance (%)</th><th>Reason</th><th>Status</th>
            </tr></thead><tbody>' . ($tbody ?: '<tr><td colspan="8" style="text-align:center">No records found.</td></tr>') . '</tbody></table></body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="variance_report_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fuel Variance Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Fuel Type', 'Expected Stock (L)', 'Actual Stock (L)', 'Variance (L)', 'Variance (%)', 'Reason', 'Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['report_date'] ?? '',
                $r['fuel_type'] ?? '',
                $r['expected_stock'] ?? 0,
                $r['actual_stock'] ?? 0,
                $r['variance_liters'] ?? 0,
                $r['variance_percent'] ?? 0,
                $r['reason'] ?? '',
                $r['status'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    case 'export_receivable':
        $rows = safe_rows($pdo, "
            SELECT ar.transaction_id,
                   COALESCE(c.name, \'Unknown Customer\') AS customer_name,
                   ar.fuel_type AS type_details,
                   ar.amount,
                   ar.status,
                   ar.due_date,
                   DATE(ar.created_at) AS created_date
            FROM accounts_receivable ar
            LEFT JOIN customers c ON c.id = ar.customer_id
            WHERE ar.station_id = ?
              AND DATE(ar.created_at) BETWEEN ? AND ?
            ORDER BY ar.created_at DESC
        ", [$station_id, $date_from, $date_to]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $statusStyle = strtolower($r['status'] ?? '') === 'paid' ? 'color:#16a34a;font-weight:bold' : 'color:#ca8a04;font-weight:bold';
                $tbody .= '<tr>
                    <td>' . htmlspecialchars($r['created_date'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['transaction_id'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['customer_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['type_details'] ?? '') . '</td>
                    <td style="text-align:right">₱' . number_format((float)($r['amount'] ?? 0), 2) . '</td>
                    <td>' . htmlspecialchars($r['due_date'] ?? '') . '</td>
                    <td style="' . $statusStyle . '">' . htmlspecialchars(ucfirst($r['status'] ?? '')) . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Accounts Receivable Report</title>
            <style>
            body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
            .hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
            .hdr h1{font-size:18px;color:#00264D;margin-bottom:4px}
            .hdr p{font-size:10px;color:#64748b;margin-top:2px}
            table{width:100%;border-collapse:collapse}
            th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
            td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
            tr:nth-child(even) td{background:#f8fafc}
            .pbtn{margin-bottom:14px}
            .pbtn button{background:#00264D;color:#fff;border:none;padding:7px 18px;border-radius:5px;font-size:12px;cursor:pointer}
            @media print{.pbtn{display:none}body{padding:0}}
            </style></head><body>
            <div class="pbtn"><button onclick="window.print()">Print / Save as PDF</button></div>
            <div class="hdr">
              <h1>Accounts Receivable Report</h1>
              <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
              <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
            </div>
            <table><thead><tr>
              <th>Created Date</th><th>Transaction ID</th><th>Customer Name</th><th>Details</th><th>Amount</th><th>Due Date</th><th>Status</th>
            </tr></thead><tbody>' . ($tbody ?: '<tr><td colspan="7" style="text-align:center">No records found.</td></tr>') . '</tbody></table></body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="accounts_receivable_report_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Accounts Receivable Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['Created Date', 'Transaction ID', 'Customer Name', 'Details', 'Amount (₱)', 'Due Date', 'Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['created_date'] ?? '',
                $r['transaction_id'] ?? '',
                $r['customer_name'] ?? '',
                $r['type_details'] ?? '',
                $r['amount'] ?? 0,
                $r['due_date'] ?? '',
                $r['status'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    case 'export_sales':
        require __DIR__ . '/_export_sales_handler.php';
        exit;

    case 'export_job_orders':
        require __DIR__ . '/_export_job_orders_handler.php';
        exit;

    case 'export_balances':
        require __DIR__ . '/_export_balances_handler.php';
        exit;

    case 'export_deliveries':
        require __DIR__ . '/_export_deliveries_handler.php';
        exit;

    case 'export_staff':
        require __DIR__ . '/_export_staff_handler.php';
        exit;

    // ── EXPORT: AUDIT TRAIL ───────────────────────────────────────────────────
    case 'export_audit':
        $user_filter   = (int)($_GET['user_id']   ?? 0);
        $action_filter = trim($_GET['action_type'] ?? '');
        $module_filter = trim($_GET['module']      ?? '');

        $sql = "SELECT al.id,
                       al.created_at,
                       u.name        AS user_name,
                       u.id          AS user_id,
                       u.role,
                       al.action_type,
                       al.entity_type AS module,
                       al.action_details AS details,
                       al.ip_address,
                       al.status
                FROM audit_logs al
                INNER JOIN users u ON u.id = al.user_id
                WHERE u.station_id = ?
                  AND DATE(al.created_at) BETWEEN ? AND ?
                  AND LOWER(TRIM(u.role)) NOT IN ('admin','superadmin','super admin','super_admin')";
        $params = [$station_id, $date_from, $date_to];
        if ($user_filter)   { $sql .= " AND al.user_id = ?";     $params[] = $user_filter; }
        if ($action_filter) { $sql .= " AND al.action_type = ?"; $params[] = $action_filter; }
        if ($module_filter) { $sql .= " AND al.entity_type = ?"; $params[] = $module_filter; }
        $sql .= " ORDER BY al.created_at DESC";

        $rows = safe_rows($pdo, $sql, $params);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $sc = strtolower($r['status'] ?? '') === 'success' ? '#16a34a' : '#dc2626';
                $tbody .= '<tr>
                    <td style="white-space:nowrap">' . htmlspecialchars($r['created_at'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['user_name'] ?? 'System') . '<br><small style="color:#64748b">ID: ' . (int)($r['user_id'] ?? 0) . '</small></td>
                    <td>' . htmlspecialchars($r['role'] ?? '') . '</td>
                    <td><strong>' . htmlspecialchars($r['action_type'] ?? '') . '</strong></td>
                    <td>' . htmlspecialchars($r['module'] ?? '') . '</td>
                    <td style="max-width:200px;word-break:break-word">' . htmlspecialchars($r['details'] ?? '') . '</td>
                    <td><code style="font-size:10px">' . htmlspecialchars($r['ip_address'] ?? '') . '</code></td>
                    <td style="color:' . $sc . ';font-weight:600">' . htmlspecialchars($r['status'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Audit Trail — Compliance Copy</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin-bottom:4px}
.hdr p{font-size:10px;color:#64748b;margin-top:2px}
.scope{background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:10px;color:#1e40af}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px;vertical-align:top}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}
.pbtn button{background:#00264D;color:#fff;border:none;padding:7px 18px;border-radius:5px;font-size:12px;cursor:pointer}
@media print{.pbtn{display:none}body{padding:0}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">&#128438; Print / Save as PDF</button></div>
<div class="hdr">
  <h1>Audit Trail — Compliance Copy</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
  <p><strong>Generated:</strong> ' . date('F j, Y H:i:s') . '</p>
</div>
<div class="scope"><strong>Scope:</strong> Staff &amp; Manager actions only. Admin oversight role is excluded from this log.</div>
<table>
<thead><tr>
  <th>Date &amp; Time</th><th>User</th><th>Role</th><th>Action</th>
  <th>Module</th><th>Details</th><th>IP Address</th><th>Status</th>
</tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:20px">No audit records found for this period.</td></tr>') . '</tbody>
</table></body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="audit_trail_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Audit Trail Report — Staff & Manager Actions Only']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Scope:', 'Staff and Manager actions only. Admin oversight role excluded.']);
        fputcsv($out, []);
        fputcsv($out, ['Date & Time', 'User Name', 'User ID', 'Role', 'Action', 'Module', 'Details', 'IP Address', 'Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['created_at']  ?? '',
                $r['user_name']   ?? 'System',
                $r['user_id']     ?? '',
                $r['role']        ?? '',
                $r['action_type'] ?? '',
                $r['module']      ?? '',
                $r['details']     ?? '',
                $r['ip_address']  ?? '',
                $r['status']      ?? '',
            ]);
        }
        fclose($out);
        exit;

    default:
        api_err('Unknown action: ' . htmlspecialchars($action));
}
