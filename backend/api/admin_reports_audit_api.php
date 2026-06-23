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
                    SELECT ($mt_date) AS sd, COALESCE(SUM(mt.total_amount), 0) AS merch_rev
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND ($mt_date) BETWEEN ? AND ?
                      AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('rejected', 'cancelled')
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
                    SELECT ($mt_date) AS sd, COALESCE(SUM(mt.total_amount), 0) AS merch_rev
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND ($mt_date) BETWEEN ? AND ?
                      AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('rejected', 'cancelled')
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
            LEFT JOIN users us       ON us.user_id   = $jo_user_expr
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
            LEFT JOIN users ue ON ue.user_id = d.encoded_by
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
              AND u.status = 'Active'
              AND LOWER(TRIM(u.role)) NOT IN ('admin', 'superadmin', 'super admin', 'super_admin')
            GROUP BY u.id, u.name, u.role
            ORDER BY total_activity DESC, u.name ASC
        ", $params);
        api_ok($rows);

    // ── AUDIT TRAIL ──────────────────────────────────────────────────────────
    // Unified compliance log: merges system-wide audit_logs with manager
    // validation events in audit_trail into one chronological view.
    case 'audit_trail':
        $user_filter   = (int)($_GET['user_id']   ?? 0);
        $action_filter = trim($_GET['action_type'] ?? '');
        $module_filter = trim($_GET['module']      ?? '');
        $status_filter = trim($_GET['status_filter'] ?? '');

        // Scope: Staff, Manager, Admin only — SuperAdmin excluded
        $role_excl = "AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('superadmin','super admin','super_admin')";

        // ── Branch 1: system-wide audit_logs ─────────────────────────────────
        $sql_logs = "SELECT
                       al.id                              AS id,
                       al.created_at                      AS created_at,
                       COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'System') AS user_name,
                       COALESCE(u.id, 0)                  AS user_id,
                       COALESCE(u.role, 'system')          AS role,
                       al.action_type COLLATE utf8mb4_general_ci AS action_type,
                       al.entity_type COLLATE utf8mb4_general_ci AS module,
                       al.action_details COLLATE utf8mb4_general_ci AS details,
                       al.ip_address COLLATE utf8mb4_general_ci AS ip_address,
                       COALESCE(al.status,'Success') COLLATE utf8mb4_general_ci AS status,
                       'system_log' COLLATE utf8mb4_general_ci AS log_source
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE u.station_id = ?
                  AND DATE(al.created_at) BETWEEN ? AND ?
                  $role_excl";
        $params_logs = [$station_id, $date_from, $date_to];

        if ($user_filter)   { $sql_logs .= " AND al.user_id = ?";       $params_logs[] = $user_filter; }
        if ($action_filter) { $sql_logs .= " AND al.action_type = ?";   $params_logs[] = $action_filter; }
        if ($module_filter) { $sql_logs .= " AND al.entity_type = ?";   $params_logs[] = $module_filter; }
        if ($status_filter) { $sql_logs .= " AND LOWER(al.status) = ?"; $params_logs[] = strtolower($status_filter); }

        // ── Branch 2: manager validation audit_trail ──────────────────────────
        $sql_trail = "SELECT
                        at.id                              AS id,
                        at.timestamp                       AS created_at,
                        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Manager') AS user_name,
                        COALESCE(u.id, 0)                  AS user_id,
                        COALESCE(u.role, 'manager')         AS role,
                        at.action_type COLLATE utf8mb4_general_ci AS action_type,
                        'transaction_validation' COLLATE utf8mb4_general_ci AS module,
                        CONCAT(
                            'Validation: ', at.action_type,
                            ' on TXN ID ', at.transaction_id,
                            CASE WHEN at.new_value IS NOT NULL AND at.new_value != ''
                                 THEN CONCAT(' | ', at.new_value)
                                 ELSE '' END
                        ) COLLATE utf8mb4_general_ci        AS details,
                        NULL                               AS ip_address,
                        'Success' COLLATE utf8mb4_general_ci AS status,
                        'validation_trail' COLLATE utf8mb4_general_ci AS log_source
                FROM audit_trail at
                LEFT JOIN users u ON u.id = at.manager_id
                WHERE at.station_id = ?
                  AND DATE(at.timestamp) BETWEEN ? AND ?";
        $params_trail = [$station_id, $date_from, $date_to];

        if ($user_filter)   { $sql_trail .= " AND at.manager_id = ?";   $params_trail[] = $user_filter; }
        if ($action_filter) { $sql_trail .= " AND at.action_type = ?";  $params_trail[] = $action_filter; }
        // module filter only applies to audit_logs side; no equivalent in audit_trail
        if ($status_filter && strtolower($status_filter) !== 'success') {
            // validation trail always succeeds — skip it if filtering by non-success
            $sql_trail .= " AND 1=0";
        }

        // ── UNION and sort ────────────────────────────────────────────────────
        $union_sql = "SELECT * FROM ({$sql_logs} UNION ALL {$sql_trail}) combined_log
                      ORDER BY created_at DESC
                      LIMIT 1000";
        $union_params = array_merge($params_logs, $params_trail);

        $rows = safe_rows($pdo, $union_sql, $union_params);
        api_ok($rows);

    // ── AUDIT SUMMARY STATS ───────────────────────────────────────────────────
    case 'audit_summary':
        $rx = "AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('superadmin','super admin','super_admin')";
        $total        = safe_val($pdo, "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE u.station_id=? AND DATE(al.created_at) BETWEEN ? AND ? $rx", [$station_id,$date_from,$date_to]);
        $failed       = safe_val($pdo, "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE u.station_id=? AND DATE(al.created_at) BETWEEN ? AND ? AND LOWER(al.status)='failed' $rx", [$station_id,$date_from,$date_to]);
        $users_active = safe_val($pdo, "SELECT COUNT(DISTINCT al.user_id) FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE u.station_id=? AND DATE(al.created_at) BETWEEN ? AND ? $rx", [$station_id,$date_from,$date_to]);
        $anomalies    = safe_val($pdo, "SELECT COUNT(*) FROM (SELECT ip_address, COUNT(*) c FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE u.station_id=? AND DATE(al.created_at) BETWEEN ? AND ? AND LOWER(al.status)='failed' $rx GROUP BY ip_address HAVING c >= 3) sub", [$station_id,$date_from,$date_to]);
        api_ok(['total'=>(int)$total,'failed'=>(int)$failed,'users_active'=>(int)$users_active,'anomalies'=>(int)$anomalies]);

    // ── ANOMALY DETECTION ─────────────────────────────────────────────────────
    case 'anomaly_detection':
        // Scope: Staff, Manager, Admin only
        $no_sa = "AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('superadmin','super admin','super_admin')";

        // Repeated failures per user/IP
        $repeated = safe_rows($pdo, "
            SELECT COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role, al.ip_address,
                   COUNT(*) AS fail_count,
                   MAX(al.created_at) AS last_attempt,
                   GROUP_CONCAT(DISTINCT al.action_type ORDER BY al.action_type SEPARATOR ', ') AS actions
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE u.station_id=?
              AND DATE(al.created_at) BETWEEN ? AND ?
              AND LOWER(al.status) = 'failed'
              $no_sa
            GROUP BY al.user_id, al.ip_address
            HAVING fail_count >= 3
            ORDER BY fail_count DESC LIMIT 50
        ", [$station_id,$date_from,$date_to]);

        // Repeated rejections (validation rejections)
        $rejections = safe_rows($pdo, "
            SELECT COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role,
                   al.action_type, COUNT(*) AS reject_count,
                   MAX(al.created_at) AS last_seen
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE u.station_id=?
              AND DATE(al.created_at) BETWEEN ? AND ?
              AND LOWER(al.action_type) IN ('reject','rejected','rejection','return')
              $no_sa
            GROUP BY al.user_id, al.action_type
            HAVING reject_count >= 2
            ORDER BY reject_count DESC LIMIT 50
        ", [$station_id,$date_from,$date_to]);

        api_ok(['repeated_failures'=>$repeated,'repeated_rejections'=>$rejections]);

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

    // ── EXPORT: FUEL MANAGEMENT ───────────────────────────────────────────
    case 'export_fuel_management':
        $dels = safe_rows($pdo, "
            SELECT DATE(fd.delivery_date) AS delivery_date, fd.fuel_type, fd.supplier,
                   fd.invoice_no, fd.delivery_liters, fd.tanker_number,
                   COALESCE(u.name,'') AS received_name, fd.status
            FROM fuel_deliveries fd
            LEFT JOIN users u ON u.user_id = fd.received_by
            WHERE fd.station_id = ? AND DATE(fd.delivery_date) BETWEEN ? AND ?
            ORDER BY fd.delivery_date DESC
        ", [$station_id, $date_from, $date_to]);

        $reads = safe_rows($pdo, "
            SELECT DATE(fr.encoded_at) AS encoded_date, fr.pump_number, fr.fuel_type,
                   fr.shift_period, fr.previous_reading, fr.present_reading, fr.difference, fr.status
            FROM fuel_readings fr
            WHERE fr.station_id = ? AND DATE(fr.encoded_at) BETWEEN ? AND ?
            ORDER BY fr.encoded_at DESC
        ", [$station_id, $date_from, $date_to]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $del_tbody = '';
            foreach ($dels as $r) {
                $del_tbody .= '<tr>
                    <td>' . htmlspecialchars($r['delivery_date'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['fuel_type'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['supplier'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['invoice_no'] ?? '') . '</td>
                    <td style="text-align:right">' . number_format($r['delivery_liters'] ?? 0, 2) . ' L</td>
                    <td>' . htmlspecialchars($r['tanker_number'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['received_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['status'] ?? '') . '</td>
                </tr>';
            }
            $read_tbody = '';
            foreach ($reads as $r) {
                $read_tbody .= '<tr>
                    <td>' . htmlspecialchars($r['encoded_date'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['pump_number'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['fuel_type'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['shift_period'] ?? '') . '</td>
                    <td style="text-align:right">' . number_format($r['previous_reading'] ?? 0, 2) . '</td>
                    <td style="text-align:right">' . number_format($r['present_reading'] ?? 0, 2) . '</td>
                    <td style="text-align:right">' . number_format($r['difference'] ?? 0, 2) . ' L</td>
                    <td>' . htmlspecialchars($r['status'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Management Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
h2{font-size:14px;color:#00264D;margin:20px 0 10px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Fuel Management Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
</div>
<h2>1. Fuel Deliveries</h2>
<table><thead><tr><th>Date</th><th>Fuel Type</th><th>Supplier</th><th>Invoice #</th><th style="text-align:right">Liters</th><th>Tanker #</th><th>Received By</th><th>Status</th></tr></thead>
<tbody>' . ($del_tbody ?: '<tr><td colspan="8" style="text-align:center">No deliveries found.</td></tr>') . '</tbody></table>
<h2>2. Pump Readings</h2>
<table><thead><tr><th>Date</th><th>Pump #</th><th>Fuel Type</th><th>Shift</th><th style="text-align:right">Prev Reading</th><th style="text-align:right">Pres Reading</th><th style="text-align:right">Difference</th><th>Status</th></tr></thead>
<tbody>' . ($read_tbody ?: '<tr><td colspan="8" style="text-align:center">No readings found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="fuel_management_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fuel Management Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['--- FUEL DELIVERIES ---']);
        fputcsv($out, ['Date', 'Fuel Type', 'Supplier', 'Invoice #', 'Delivery Liters', 'Tanker #', 'Received By', 'Status']);
        foreach ($dels as $r) {
            fputcsv($out, [
                $r['delivery_date'] ?? '',
                $r['fuel_type'] ?? '',
                $r['supplier'] ?? '',
                $r['invoice_no'] ?? '',
                $r['delivery_liters'] ?? 0,
                $r['tanker_number'] ?? '',
                $r['received_name'] ?? '',
                $r['status'] ?? '',
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['--- PUMP READINGS ---']);
        fputcsv($out, ['Date', 'Pump #', 'Fuel Type', 'Shift', 'Previous Reading', 'Present Reading', 'Difference (L)', 'Status']);
        foreach ($reads as $r) {
            fputcsv($out, [
                $r['encoded_date'] ?? '',
                $r['pump_number'] ?? '',
                $r['fuel_type'] ?? '',
                $r['shift_period'] ?? '',
                $r['previous_reading'] ?? 0,
                $r['present_reading'] ?? 0,
                $r['difference'] ?? 0,
                $r['status'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: MERCHANDISE DELIVERIES ────────────────────────────────────
    case 'export_merch_deliveries':
        $rows = safe_rows($pdo, "
            SELECT d.id, COALESCE(d.dr_number,d.delivery_ref,'') AS dr_number,
                   COALESCE(d.source_ref,'') AS source_ref,
                   d.supplier, d.product, COALESCE(d.quantity,0) AS quantity,
                   COALESCE(d.expected_quantity,d.quantity,0) AS expected_quantity,
                   COALESCE(d.actual_quantity,d.quantity,0) AS actual_quantity,
                   COALESCE(d.damaged_quantity,0) AS damaged_quantity,
                   DATE(COALESCE(d.delivery_date,d.created_at)) AS delivery_date,
                   d.status
            FROM deliveries_oversight d
            WHERE d.station_id = ? AND d.delivery_type='merchandise'
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
            ORDER BY delivery_date DESC
        ", [$station_id, $date_from, $date_to]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $tbody .= '<tr>
                    <td>' . htmlspecialchars($r['dr_number'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['source_ref'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['supplier'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['product'] ?? '') . '</td>
                    <td style="text-align:right">' . number_format($r['quantity'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['expected_quantity'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['actual_quantity'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['damaged_quantity'] ?? 0) . '</td>
                    <td>' . htmlspecialchars($r['delivery_date'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['status'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Merchandise Deliveries Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Merchandise Deliveries Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
</div>
<table><thead><tr><th>DR #</th><th>PO #</th><th>Supplier</th><th>Product</th><th style="text-align:right">PO Qty</th><th style="text-align:right">Expected</th><th style="text-align:right">Actual</th><th style="text-align:right">Damaged</th><th>Date</th><th>Status</th></tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="10" style="text-align:center">No records found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="merchandise_deliveries_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Merchandise Deliveries Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['DR #', 'PO #', 'Supplier', 'Product', 'PO Qty', 'Expected Qty', 'Actual Qty', 'Damaged Qty', 'Date', 'Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['dr_number'] ?? '',
                $r['source_ref'] ?? '',
                $r['supplier'] ?? '',
                $r['product'] ?? '',
                $r['quantity'] ?? 0,
                $r['expected_quantity'] ?? 0,
                $r['actual_quantity'] ?? 0,
                $r['damaged_quantity'] ?? 0,
                $r['delivery_date'] ?? '',
                $r['status'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: INVENTORY ──────────────────────────────────────────────────
    case 'export_inventory':
        $rows = safe_rows($pdo, "
            SELECT ip.sku, ip.product_name, ip.category, ip.supplier,
                   ip.unit_cost, ip.unit_price,
                   COALESCE(ip.stock_quantity, ip.stock, 0) AS stock_quantity,
                   COALESCE(ip.min_stock,0) AS min_stock,
                   ip.status
            FROM inventory_products ip
            WHERE ip.station_id = ?
            ORDER BY ip.category, ip.product_name
        ", [$station_id]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $tbody .= '<tr>
                    <td><code>' . htmlspecialchars($r['sku'] ?? '') . '</code></td>
                    <td>' . htmlspecialchars($r['product_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['category'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['supplier'] ?? '') . '</td>
                    <td style="text-align:right">₱' . number_format($r['unit_cost'] ?? 0, 2) . '</td>
                    <td style="text-align:right">₱' . number_format($r['unit_price'] ?? 0, 2) . '</td>
                    <td style="text-align:right">' . number_format($r['stock_quantity'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['min_stock'] ?? 0) . '</td>
                    <td>' . htmlspecialchars($r['status'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Inventory Status Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Inventory Status Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
</div>
<table><thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Supplier</th><th style="text-align:right">Cost</th><th style="text-align:right">Price</th><th style="text-align:right">Stock</th><th style="text-align:right">Min Stock</th><th>Status</th></tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="9" style="text-align:center">No products found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="inventory_report_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Inventory Status Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['SKU', 'Product', 'Category', 'Supplier', 'Unit Cost', 'Unit Price', 'Stock Qty', 'Min Stock', 'Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['sku'] ?? '',
                $r['product_name'] ?? '',
                $r['category'] ?? '',
                $r['supplier'] ?? '',
                $r['unit_cost'] ?? 0,
                $r['unit_price'] ?? 0,
                $r['stock_quantity'] ?? 0,
                $r['min_stock'] ?? 0,
                $r['status'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: CUSTOMERS ──────────────────────────────────────────────────
    case 'export_customers':
        $rows = safe_rows($pdo, "
            SELECT c.id, c.name, c.type, COALESCE(c.contact_number,c.phone,'') AS contact_number,
                   COALESCE(c.balance,c.current_balance,0) AS balance,
                   COALESCE(c.credit_limit,0) AS credit_limit,
                   COALESCE(c.payment_terms,'') AS payment_terms,
                   COALESCE(c.account_status,c.status,'Active') AS account_status
            FROM customers c
            WHERE c.station_id = ?
            ORDER BY name ASC
        ", [$station_id]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $tbody .= '<tr>
                    <td>' . htmlspecialchars($r['id'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['type'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['contact_number'] ?? '') . '</td>
                    <td style="text-align:right">₱' . number_format($r['balance'] ?? 0, 2) . '</td>
                    <td style="text-align:right">₱' . number_format($r['credit_limit'] ?? 0, 2) . '</td>
                    <td>' . htmlspecialchars($r['payment_terms'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['account_status'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Customer Accounts Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Customer Accounts Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
</div>
<table><thead><tr><th>ID</th><th>Customer Name</th><th>Type</th><th>Contact</th><th style="text-align:right">Balance</th><th style="text-align:right">Credit Limit</th><th>Payment Terms</th><th>Status</th></tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="8" style="text-align:center">No customers found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="customer_accounts_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Customer Accounts Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['ID', 'Customer Name', 'Type', 'Contact', 'Outstanding Balance', 'Credit Limit', 'Payment Terms', 'Status']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'] ?? '',
                $r['name'] ?? '',
                $r['type'] ?? '',
                $r['contact_number'] ?? '',
                $r['balance'] ?? 0,
                $r['credit_limit'] ?? 0,
                $r['payment_terms'] ?? '',
                $r['account_status'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: SUPPLIERS ──────────────────────────────────────────────────
    case 'export_suppliers':
        $rows = safe_rows($pdo, "
            SELECT d.supplier AS supplier_name,
                   COALESCE(s.contact_person,'') AS contact_person,
                   COUNT(d.id) AS total_deliveries,
                   SUM(CASE WHEN LOWER(d.status) IN ('confirmed','approved','validated','ready for stock-in','adjusted') THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN LOWER(d.status) IN ('discrepancy','flagged') THEN 1 ELSE 0 END) AS discrepancy_count,
                   SUM(CASE WHEN LOWER(d.status) IN ('rejected','returned','returned to supplier') THEN 1 ELSE 0 END) AS rejected_count,
                   COALESCE(SUM(d.payable_amount),0) AS total_payable
            FROM deliveries_oversight d
            LEFT JOIN suppliers s ON LOWER(TRIM(s.name)) = LOWER(TRIM(d.supplier))
            WHERE d.station_id = ?
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
            GROUP BY d.supplier ORDER BY total_payable DESC
        ", [$station_id, $date_from, $date_to]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $i => $r) {
                $tbody .= '<tr>
                    <td>' . ($i + 1) . '</td>
                    <td>' . htmlspecialchars($r['supplier_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['contact_person'] ?? '') . '</td>
                    <td style="text-align:right">' . number_format($r['total_deliveries'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['approved_count'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['discrepancy_count'] ?? 0) . '</td>
                    <td style="text-align:right">' . number_format($r['rejected_count'] ?? 0) . '</td>
                    <td style="text-align:right">₱' . number_format($r['total_payable'] ?? 0, 2) . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Supplier Performance Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Supplier Performance Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
</div>
<table><thead><tr><th>Rank</th><th>Supplier Name</th><th>Contact</th><th style="text-align:right">Total Deliveries</th><th style="text-align:right">Approved</th><th style="text-align:right">Discrepancies</th><th style="text-align:right">Rejected</th><th style="text-align:right">Total Payable</th></tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="8" style="text-align:center">No records found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="supplier_performance_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Supplier Performance Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['Rank', 'Supplier Name', 'Contact Person', 'Total Deliveries', 'Approved', 'Discrepancies', 'Rejected', 'Total Payable']);
        foreach ($rows as $i => $r) {
            fputcsv($out, [
                $i + 1,
                $r['supplier_name'] ?? '',
                $r['contact_person'] ?? '',
                $r['total_deliveries'] ?? 0,
                $r['approved_count'] ?? 0,
                $r['discrepancy_count'] ?? 0,
                $r['rejected_count'] ?? 0,
                $r['total_payable'] ?? 0,
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: FINANCIAL ──────────────────────────────────────────────────
    case 'export_financial':
        $rows = safe_rows($pdo, "
            SELECT d.sale_date,
                   COALESCE(f.fuel_rev,0) AS fuel_revenue,
                   COALESCE(m.merch_rev,0) AS merch_revenue,
                   COALESCE(f.fuel_rev,0)+COALESCE(m.merch_rev,0) AS total_revenue,
                   COALESCE(p.payables,0) AS supplier_payables
            FROM (
                SELECT DISTINCT DATE(ft.transaction_date) AS sale_date FROM fuel_transactions ft
                WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                UNION
                SELECT DISTINCT ($mt_date) FROM merchandise_transactions mt
                WHERE mt.station_id=? AND ($mt_date) BETWEEN ? AND ?
            ) d
            LEFT JOIN (
                SELECT DATE(transaction_date) sd, COALESCE(SUM(total_amount),0) fuel_rev
                FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(transaction_date)
            ) f ON f.sd=d.sale_date
            LEFT JOIN (
                SELECT ($mt_date) sd, COALESCE(SUM(mt.total_amount),0) merch_rev
                FROM merchandise_transactions mt WHERE mt.station_id=? AND ($mt_date) BETWEEN ? AND ?
                AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('rejected','cancelled','voided')
                GROUP BY ($mt_date)
            ) m ON m.sd=d.sale_date
            LEFT JOIN (
                SELECT DATE(COALESCE(delivery_date,created_at)) sd, COALESCE(SUM(payable_amount),0) payables
                FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ?
                GROUP BY DATE(COALESCE(delivery_date,created_at))
            ) p ON p.sd=d.sale_date
            ORDER BY d.sale_date DESC
        ", [
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
        ]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $rev = ($r['fuel_revenue'] ?? 0) + ($r['merch_revenue'] ?? 0);
                $net = $rev - ($r['supplier_payables'] ?? 0);
                $tbody .= '<tr>
                    <td>' . htmlspecialchars($r['sale_date'] ?? '') . '</td>
                    <td style="text-align:right">₱' . number_format($r['fuel_revenue'] ?? 0, 2) . '</td>
                    <td style="text-align:right">₱' . number_format($r['merch_revenue'] ?? 0, 2) . '</td>
                    <td style="text-align:right">₱' . number_format($rev, 2) . '</td>
                    <td style="text-align:right">₱' . number_format($r['supplier_payables'] ?? 0, 2) . '</td>
                    <td style="text-align:right">₱' . number_format($net, 2) . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Financial Cash Flow Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Financial Cash Flow Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
</div>
<table><thead><tr><th>Date</th><th style="text-align:right">Fuel Revenue</th><th style="text-align:right">Merchandise Revenue</th><th style="text-align:right">Total Revenue</th><th style="text-align:right">Supplier Payables</th><th style="text-align:right">Net Cash Flow</th></tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="6" style="text-align:center">No records found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="financial_cashflow_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Financial Cash Flow Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Fuel Revenue', 'Merchandise Revenue', 'Total Revenue', 'Supplier Payables', 'Net Cash Flow']);
        foreach ($rows as $r) {
            $rev = ($r['fuel_revenue'] ?? 0) + ($r['merch_revenue'] ?? 0);
            $net = $rev - ($r['supplier_payables'] ?? 0);
            fputcsv($out, [
                $r['sale_date'] ?? '',
                $r['fuel_revenue'] ?? 0,
                $r['merch_revenue'] ?? 0,
                $rev,
                $r['supplier_payables'] ?? 0,
                $net,
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: CALENDAR ───────────────────────────────────────────────────
    case 'export_calendar':
        $rows = safe_rows($pdo, "
            SELECT ce.id, DATE(ce.event_date) AS event_date,
                   COALESCE(TIME_FORMAT(ce.event_time,'%h:%i %p'),'') AS event_time,
                   ce.event_type, ce.work_description, ce.status,
                   COALESCE(us.name,'') AS staff_name,
                   COALESCE(um.name,'') AS manager_name,
                   ce.remarks
            FROM calendar_events ce
            LEFT JOIN users us ON us.user_id = ce.staff_assigned
            LEFT JOIN users um ON um.user_id = ce.manager_assigned
            WHERE ce.station_id = ?
              AND DATE(ce.event_date) BETWEEN ? AND ?
            ORDER BY ce.event_date ASC
        ", [$station_id, $date_from, $date_to]);

        if ($format === 'pdf') {
            header('Content-Type: text/html; charset=UTF-8');
            $tbody = '';
            foreach ($rows as $r) {
                $tbody .= '<tr>
                    <td>' . htmlspecialchars($r['event_date'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['event_time'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['event_type'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['work_description'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['staff_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['manager_name'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['status'] ?? '') . '</td>
                    <td>' . htmlspecialchars($r['remarks'] ?? '') . '</td>
                </tr>';
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Calendar & Scheduling Report</title>
<style>
body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b;padding:20px}
.hdr{margin-bottom:16px;border-bottom:3px solid #00264D;padding-bottom:10px}
.hdr h1{font-size:18px;color:#00264D;margin:0 0 4px 0}
table{width:100%;border-collapse:collapse}
th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase}
td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
tr:nth-child(even) td{background:#f8fafc}
.pbtn{margin-bottom:14px}@media print{.pbtn{display:none}}
</style></head><body>
<div class="pbtn"><button onclick="window.print()">Print Report</button></div>
<div class="hdr">
  <h1>Calendar & Scheduling Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
</div>
<table><thead><tr><th>Date</th><th>Time</th><th>Event Type</th><th>Description</th><th>Staff Assigned</th><th>Manager</th><th>Status</th><th>Remarks</th></tr></thead>
<tbody>' . ($tbody ?: '<tr><td colspan="8" style="text-align:center">No scheduled events found.</td></tr>') . '</tbody></table>
</body></html>';
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="calendar_scheduling_' . $date_from . '_to_' . $date_to . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Calendar & Scheduling Report']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Time', 'Event Type', 'Description', 'Staff Assigned', 'Manager', 'Status', 'Remarks']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['event_date'] ?? '',
                $r['event_time'] ?? '',
                $r['event_type'] ?? '',
                $r['work_description'] ?? '',
                $r['staff_name'] ?? '',
                $r['manager_name'] ?? '',
                $r['status'] ?? '',
                $r['remarks'] ?? '',
            ]);
        }
        fclose($out);
        exit;

    // ── EXPORT: AUDIT TRAIL ───────────────────────────────────────────────────
    case 'export_audit':
        $user_filter   = (int)($_GET['user_id']   ?? 0);
        $action_filter = trim($_GET['action_type'] ?? '');
        $module_filter = trim($_GET['module']      ?? '');
        $status_filter = trim($_GET['status_filter'] ?? '');

        // Scope: Staff, Manager, Admin only — SuperAdmin excluded
        $sql = "SELECT al.id,
                       al.created_at,
                       COALESCE(u.name, 'System') AS user_name,
                       COALESCE(u.id, 0)          AS user_id,
                       COALESCE(u.role, 'system')  AS role,
                       al.action_type,
                       al.entity_type              AS module,
                       al.action_details           AS details,
                       al.ip_address,
                       al.status
                FROM audit_logs al
                LEFT JOIN users u ON u.user_id = al.user_id
                WHERE u.station_id = ?
                  AND DATE(al.created_at) BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('superadmin','super admin','super_admin')";
        $params = [$station_id, $date_from, $date_to];

        if ($user_filter)   { $sql .= " AND al.user_id = ?";       $params[] = $user_filter; }
        if ($action_filter) { $sql .= " AND al.action_type = ?";   $params[] = $action_filter; }
        if ($module_filter) { $sql .= " AND al.entity_type = ?";   $params[] = $module_filter; }
        if ($status_filter) { $sql .= " AND LOWER(al.status) = ?"; $params[] = strtolower($status_filter); }
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
<title>Audit Trail — Full Compliance Copy</title>
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
  <h1>Audit Trail — Full Compliance Copy</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
  <p><strong>Generated:</strong> ' . date('F j, Y H:i:s') . '</p>
</div>
<div class="scope"><strong>Scope:</strong> Full Compliance Trail tracking Staff activities (encoding), Manager reviews (validation), and Admin decisions.</div>
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
        fputcsv($out, ['Audit Trail Report — Full Compliance Copy']);
        fputcsv($out, ['Station:', $station_name]);
        fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
        fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Scope:', 'Full trail logs for Staff encoding, Manager validation, and Admin oversight actions.']);
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

    // ── FUEL DELIVERIES REPORT ───────────────────────────────────────────
    case 'fuel_deliveries_report':
        $rows = safe_rows($pdo, "
            SELECT DATE(fd.delivery_date) AS delivery_date, fd.fuel_type, fd.supplier,
                   fd.invoice_no, fd.delivery_liters, fd.tanker_number,
                   COALESCE(u.name,'') AS received_name, fd.status
            FROM fuel_deliveries fd
            LEFT JOIN users u ON u.user_id = fd.received_by
            WHERE fd.station_id = ? AND DATE(fd.delivery_date) BETWEEN ? AND ?
            ORDER BY fd.delivery_date DESC LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── PUMP READINGS REPORT ─────────────────────────────────────────────
    case 'pump_readings_report':
        $rows = safe_rows($pdo, "
            SELECT DATE(fr.encoded_at) AS encoded_date, fr.pump_number, fr.fuel_type,
                   fr.shift_period, fr.previous_reading, fr.present_reading, fr.difference, fr.status
            FROM fuel_readings fr
            WHERE fr.station_id = ? AND DATE(fr.encoded_at) BETWEEN ? AND ?
            ORDER BY fr.encoded_at DESC LIMIT 1000
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── MERCH DELIVERIES: DR vs PO ───────────────────────────────────────
    case 'merch_deliveries_drpo':
        $rows = safe_rows($pdo, "
            SELECT d.id, COALESCE(d.dr_number,d.delivery_ref,'') AS dr_number,
                   COALESCE(d.source_ref,'') AS source_ref,
                   d.supplier, d.product, COALESCE(d.quantity,0) AS quantity,
                   COALESCE(d.expected_quantity,d.quantity,0) AS expected_quantity,
                   COALESCE(d.actual_quantity,d.quantity,0) AS actual_quantity,
                   COALESCE(d.damaged_quantity,0) AS damaged_quantity,
                   DATE(COALESCE(d.delivery_date,d.created_at)) AS delivery_date,
                   d.status
            FROM deliveries_oversight d
            WHERE d.station_id = ? AND d.delivery_type='merchandise'
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
            ORDER BY delivery_date DESC LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── MERCH DELIVERIES: Issues ─────────────────────────────────────────
    case 'merch_deliveries_issues':
        $rows = safe_rows($pdo, "
            SELECT d.id, COALESCE(d.dr_number,d.delivery_ref,'') AS dr_number,
                   d.supplier, d.product,
                   COALESCE(d.discrepancy_type,d.status,'') AS discrepancy_type,
                   COALESCE(d.damaged_quantity,0) AS damaged_quantity,
                   COALESCE(d.actual_quantity,0) AS actual_quantity,
                   COALESCE(d.return_reason,d.remarks,'') AS return_reason,
                   COALESCE(d.resolution_action,'') AS resolution_action,
                   DATE(COALESCE(d.delivery_date,d.created_at)) AS delivery_date,
                   d.status
            FROM deliveries_oversight d
            WHERE d.station_id = ? AND d.delivery_type='merchandise'
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
              AND (COALESCE(d.damaged_quantity,0) > 0
                   OR LOWER(d.status) IN ('rejected','returned','discrepancy','returned to supplier','flagged'))
            ORDER BY delivery_date DESC LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── SUPPLIER PAYABLES (Merchandise) ─────────────────────────────────
    case 'merch_supplier_payables':
        $rows = safe_rows($pdo, "
            SELECT d.supplier AS supplier,
                   COUNT(d.id) AS total_deliveries,
                   COALESCE(SUM(d.expected_amount),0) AS total_expected,
                   COALESCE(SUM(d.payable_amount),0) AS total_payable,
                   SUM(CASE WHEN LOWER(d.status) IN ('confirmed','approved','validated','ready for stock-in','adjusted') THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN LOWER(d.status) IN ('rejected','discrepancy','flagged') THEN 1 ELSE 0 END) AS rejected_count
            FROM deliveries_oversight d
            WHERE d.station_id = ? AND d.delivery_type='merchandise'
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
            GROUP BY d.supplier ORDER BY total_payable DESC
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── INVENTORY REPORT ────────────────────────────────────────────────
    case 'inventory_report':
        $rows = safe_rows($pdo, "
            SELECT ip.sku, ip.product_name, ip.category, ip.supplier,
                   ip.unit_cost, ip.unit_price,
                   COALESCE(ip.stock_quantity, ip.stock, 0) AS stock_quantity,
                   COALESCE(ip.min_stock,0) AS min_stock,
                   ip.status
            FROM inventory_products ip
            WHERE ip.station_id = ?
            ORDER BY ip.category, ip.product_name LIMIT 1000
        ", [$station_id]);
        api_ok($rows);

    // ── CUSTOMER REPORT: Balances ────────────────────────────────────────
    case 'customer_report_balances':
        $rows = safe_rows($pdo, "
            SELECT c.id, c.name, c.type, COALESCE(c.contact_number,c.phone,'') AS contact_number,
                   COALESCE(c.balance,c.current_balance,0) AS balance,
                   COALESCE(c.credit_limit,0) AS credit_limit,
                   COALESCE(c.payment_terms,'') AS payment_terms,
                   COALESCE(c.account_status,c.status,'Active') AS account_status
            FROM customers c
            WHERE c.station_id = ?
              AND COALESCE(c.balance,c.current_balance,0) > 0
            ORDER BY balance DESC LIMIT 500
        ", [$station_id]);
        api_ok($rows);

    // ── CUSTOMER REPORT: Purchase History ───────────────────────────────
    case 'customer_purchase_history':
        $rows = safe_rows($pdo, "
            SELECT ($mt_date) AS txn_date,
                   COALESCE(mt.customer_name,
                     TRIM(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,''))),
                     'Walk-in') AS customer_name,
                   mt.transaction_id,
                   mt.total_amount,
                   mt.payment_method,
                   COALESCE(mt.validation_status,mt.payment_status,'Pending') AS validation_status
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND ($mt_date) BETWEEN ? AND ?
              AND (mt.customer_name IS NOT NULL OR mt.credit_customer_id IS NOT NULL)
            ORDER BY mt.created_at DESC LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── SUPPLIER PERFORMANCE ────────────────────────────────────────────
    case 'supplier_performance':
        $rows = safe_rows($pdo, "
            SELECT d.supplier AS supplier_name,
                   COALESCE(s.contact_person,'') AS contact_person,
                   COUNT(d.id) AS total_deliveries,
                   SUM(CASE WHEN LOWER(d.status) IN ('confirmed','approved','validated','ready for stock-in','adjusted') THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN LOWER(d.status) IN ('discrepancy','flagged') THEN 1 ELSE 0 END) AS discrepancy_count,
                   SUM(CASE WHEN LOWER(d.status) IN ('rejected','returned','returned to supplier') THEN 1 ELSE 0 END) AS rejected_count,
                   COALESCE(SUM(d.payable_amount),0) AS total_payable
            FROM deliveries_oversight d
            LEFT JOIN suppliers s ON LOWER(TRIM(s.name)) = LOWER(TRIM(d.supplier))
            WHERE d.station_id = ?
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
            GROUP BY d.supplier ORDER BY approved_count DESC, total_deliveries DESC
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── CASH FLOW REPORT ────────────────────────────────────────────────
    case 'cash_flow_report':
        $rows = safe_rows($pdo, "
            SELECT d.sale_date,
                   COALESCE(f.fuel_rev,0) AS fuel_revenue,
                   COALESCE(m.merch_rev,0) AS merch_revenue,
                   COALESCE(f.fuel_rev,0)+COALESCE(m.merch_rev,0) AS total_revenue,
                   COALESCE(p.payables,0) AS supplier_payables
            FROM (
                SELECT DISTINCT DATE(ft.transaction_date) AS sale_date FROM fuel_transactions ft
                WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                UNION
                SELECT DISTINCT ($mt_date) FROM merchandise_transactions mt
                WHERE mt.station_id=? AND ($mt_date) BETWEEN ? AND ?
            ) d
            LEFT JOIN (
                SELECT DATE(transaction_date) sd, COALESCE(SUM(total_amount),0) fuel_rev
                FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(transaction_date)
            ) f ON f.sd=d.sale_date
            LEFT JOIN (
                SELECT ($mt_date) sd, COALESCE(SUM(mt.total_amount),0) merch_rev
                FROM merchandise_transactions mt WHERE mt.station_id=? AND ($mt_date) BETWEEN ? AND ?
                AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('rejected','cancelled','voided')
                GROUP BY ($mt_date)
            ) m ON m.sd=d.sale_date
            LEFT JOIN (
                SELECT DATE(COALESCE(delivery_date,created_at)) sd, COALESCE(SUM(payable_amount),0) payables
                FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ?
                GROUP BY DATE(COALESCE(delivery_date,created_at))
            ) p ON p.sd=d.sale_date
            ORDER BY d.sale_date DESC
        ", [
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
            $station_id,$date_from,$date_to,
        ]);
        api_ok($rows);

    // ── DELIVERY ADJUSTMENTS ─────────────────────────────────────────────
    case 'delivery_adjustments':
        $rows = safe_rows($pdo, "
            SELECT COALESCE(d.dr_number,d.delivery_ref,'') AS dr_number,
                   d.supplier, d.product,
                   COALESCE(d.discrepancy_type,'Adjustment') AS discrepancy_type,
                   COALESCE(d.expected_amount,0) AS expected_amount,
                   COALESCE(d.payable_amount,0) AS payable_amount,
                   DATE(COALESCE(d.delivery_date,d.created_at)) AS delivery_date,
                   d.status
            FROM deliveries_oversight d
            WHERE d.station_id=? AND d.delivery_type='merchandise'
              AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
              AND (COALESCE(d.expected_amount,0) != COALESCE(d.payable_amount,0)
                   OR COALESCE(d.damaged_quantity,0) > 0)
            ORDER BY delivery_date DESC LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    // ── CALENDAR REPORT ──────────────────────────────────────────────────
    case 'calendar_report':
        $rows = safe_rows($pdo, "
            SELECT ce.id, DATE(ce.event_date) AS event_date,
                   COALESCE(TIME_FORMAT(ce.event_time,'%h:%i %p'),'') AS event_time,
                   ce.event_type, ce.work_description, ce.status,
                   COALESCE(us.name,'') AS staff_name,
                   COALESCE(um.name,'') AS manager_name,
                   ce.remarks
            FROM calendar_events ce
            LEFT JOIN users us ON us.user_id = ce.staff_assigned
            LEFT JOIN users um ON um.user_id = ce.manager_assigned
            WHERE ce.station_id = ?
              AND DATE(ce.event_date) BETWEEN ? AND ?
            ORDER BY ce.event_date ASC LIMIT 500
        ", [$station_id, $date_from, $date_to]);
        api_ok($rows);

    default:
        api_err('Unknown action: ' . htmlspecialchars($action));
}

