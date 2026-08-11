<?php
/**
 * POST/GET /backend/api/transaction_reports_api.php
 * Unified transaction reports API — auto-calculates from live database.
 * Covers: Merchandise Sales, Job Order Sales, Payment Summary,
 *         AR Summary, Voided, Adjusted, Combined totals.
 */
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../transaction_schema_fix.php';

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

$raw  = file_get_contents('php://input');
$post = $raw ? (json_decode($raw, true) ?? []) : [];
$post = array_merge($_GET, $_POST, $post);

$date_from  = trim($post['date_from']  ?? date('Y-m-01'));
$date_to    = trim($post['date_to']    ?? date('Y-m-d'));
$shift      = trim($post['shift']      ?? '');
$staff_id   = (int)($post['staff_id']  ?? 0);
$section    = trim($post['section']    ?? 'all');

// ── helpers ──────────────────────────────────────────────────────────────────
function rpt_cols(PDO $pdo, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $cache[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) { $cache[$table] = []; }
    }
    return $cache[$table];
}
function rpt_has(PDO $pdo, string $table, string $col): bool {
    return in_array($col, rpt_cols($pdo, $table));
}
function rpt_col(PDO $pdo, string $table, string $alias, string $col, string $fallback): string {
    return rpt_has($pdo, $table, $col) ? "{$alias}.{$col}" : $fallback;
}

$shift_expr   = rpt_col($pdo, 'merchandise_transactions', 'mt', 'shift_name',
                    rpt_col($pdo, 'merchandise_transactions', 'mt', 'shift_period', "'N/A'"));
$status_expr  = rpt_col($pdo, 'merchandise_transactions', 'mt', 'validation_status', "'Completed'");
$pay_st_expr  = rpt_col($pdo, 'merchandise_transactions', 'mt', 'payment_status', "'Paid'");
$date_expr    = rpt_col($pdo, 'merchandise_transactions', 'mt', 'transaction_date', 'mt.created_at');

$base_where   = "station_id = :station_id AND DATE($date_expr) BETWEEN :df AND :dt";
$base_params  = [':station_id'=>$station_id, ':df'=>$date_from, ':dt'=>$date_to];

if ($shift)    { $base_where .= " AND $shift_expr = :shift";    $base_params[':shift']    = $shift; }
if ($staff_id) { $base_where .= " AND mt.staff_id = :staff_id"; $base_params[':staff_id'] = $staff_id; }

$result = [];

// ── 1. Merchandise Sales (Merchandise Only + Combined merchandise portion) ───
if ($section === 'all' || $section === 'merchandise') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT mt.id) AS txn_count,
                COALESCE(SUM(CASE WHEN mt.transaction_type IN ('merchandise','combined','Merchandise','combined') OR mt.transaction_type IS NULL THEN mt.total_amount ELSE 0 END),0) AS merchandise_total,
                COALESCE(SUM(CASE WHEN LOWER(mt.transaction_type) = 'merchandise' THEN mt.total_amount ELSE 0 END),0) AS merch_only_total,
                COALESCE(SUM(CASE WHEN LOWER(mt.transaction_type) = 'combined' THEN mt.total_amount ELSE 0 END),0) AS combined_total
            FROM merchandise_transactions mt
            WHERE $base_where
              AND LOWER({$status_expr}) NOT IN ('voided','cancelled')
        ");
        $stmt->execute($base_params);
        $result['merchandise'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $result['merchandise'] = ['error' => $e->getMessage()]; }
}

// ── 2. Job Order Sales ─────────────────────────────────────────────────────
if ($section === 'all' || $section === 'job_orders') {
    try {
        $jo_date_expr = rpt_col($pdo, 'job_orders', 'jo', 'created_at', 'jo.created_at');
        $jo_stat_expr = rpt_col($pdo, 'job_orders', 'jo', 'validation_status',
                            rpt_col($pdo, 'job_orders', 'jo', 'status', "'Completed'"));
        $jo_where = "jo.station_id = :station_id AND DATE($jo_date_expr) BETWEEN :df AND :dt
                     AND LOWER($jo_stat_expr) NOT IN ('voided','cancelled')";
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS jo_count,
                COALESCE(SUM(COALESCE(jo.total_cost, jo.estimated_cost, 0)),0) AS jo_total,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(jo.payment_status,'')) IN ('completed','in progress','released') 
                             THEN COALESCE(jo.total_cost,jo.estimated_cost,0) ELSE 0 END),0) AS jo_completed_total
            FROM job_orders jo
            WHERE $jo_where
        ");
        $stmt->execute([':station_id'=>$station_id,':df'=>$date_from,':dt'=>$date_to]);
        $result['job_orders'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $result['job_orders'] = ['error' => $e->getMessage()]; }
}

// ── 3. Payment Method Summary ──────────────────────────────────────────────
if ($section === 'all' || $section === 'payment_summary') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COUNT(*) AS txn_count,
                COALESCE(SUM(mt.total_amount),0) AS total_amount
            FROM merchandise_transactions mt
            WHERE $base_where
              AND LOWER({$status_expr}) NOT IN ('voided','cancelled')
            GROUP BY COALESCE(mt.payment_method,'Cash')
            ORDER BY total_amount DESC
        ");
        $stmt->execute($base_params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure all 7 standardized payment methods appear
        $methods = ['Cash','Credit Card','Debit Card','GCash','Maya','Petron Fleet Card','Credit Account'];
        $indexed = [];
        foreach ($rows as $r) { $indexed[$r['payment_method']] = $r; }
        $normalized = [];
        foreach ($methods as $m) {
            $normalized[] = [
                'payment_method' => $m,
                'txn_count'      => (int)($indexed[$m]['txn_count'] ?? 0),
                'total_amount'   => (float)($indexed[$m]['total_amount'] ?? 0)
            ];
        }
        // Catch any extra methods not in our 7
        foreach ($rows as $r) {
            if (!in_array($r['payment_method'], $methods)) {
                $normalized[] = $r;
            }
        }
        $result['payment_summary'] = $normalized;
    } catch (Exception $e) { $result['payment_summary'] = ['error' => $e->getMessage()]; }
}

// ── 4. Accounts Receivable Summary ────────────────────────────────────────
if ($section === 'all' || $section === 'ar_summary') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS ar_txn_count,
                COALESCE(SUM(mt.total_amount),0) AS ar_total_amount,
                COALESCE(SUM(COALESCE(mt.amount_paid,0)),0) AS ar_amount_paid,
                COALESCE(SUM(GREATEST(0, mt.total_amount - COALESCE(mt.amount_paid,0))),0) AS ar_outstanding
            FROM merchandise_transactions mt
            WHERE $base_where
              AND LOWER(COALESCE(mt.payment_method,'')) IN ('credit account','credit','ar')
              AND LOWER({$status_expr}) NOT IN ('voided','cancelled')
        ");
        $stmt->execute($base_params);
        $result['ar_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $result['ar_summary'] = ['error' => $e->getMessage()]; }
}

// ── 5. Voided Transactions ─────────────────────────────────────────────────
if ($section === 'all' || $section === 'voided') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS void_count,
                COALESCE(SUM(mt.total_amount),0) AS voided_amount
            FROM merchandise_transactions mt
            WHERE $base_where
              AND LOWER({$status_expr}) = 'voided'
        ");
        $stmt->execute($base_params);
        $result['voided'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $result['voided'] = ['error' => $e->getMessage()]; }
}

// ── 6. Adjusted Transactions ──────────────────────────────────────────────
if ($section === 'all' || $section === 'adjusted') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS adjusted_count,
                COALESCE(SUM(mt.total_amount),0) AS adjusted_total_amount
            FROM merchandise_transactions mt
            WHERE $base_where
              AND LOWER({$status_expr}) = 'adjusted'
        ");
        $stmt->execute($base_params);
        $result['adjusted'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $result['adjusted'] = ['error' => $e->getMessage()]; }
}

// ── 7. Grand Summary ──────────────────────────────────────────────────────
if ($section === 'all' || $section === 'grand') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_transactions,
                COALESCE(SUM(CASE WHEN LOWER({$status_expr}) NOT IN ('voided','cancelled') THEN mt.total_amount ELSE 0 END),0) AS gross_sales,
                COALESCE(SUM(CASE WHEN LOWER({$status_expr}) = 'voided' THEN mt.total_amount ELSE 0 END),0) AS voided_sales,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','combined') 
                                   AND LOWER({$status_expr}) NOT IN ('voided','cancelled') THEN mt.total_amount ELSE 0 END),0) AS jo_sales,
                COALESCE(SUM(CASE WHEN LOWER(COALESCE(mt.transaction_type,'merchandise')) = 'merchandise'
                                   AND LOWER({$status_expr}) NOT IN ('voided','cancelled') THEN mt.total_amount ELSE 0 END),0) AS merch_sales
            FROM merchandise_transactions mt
            WHERE $base_where
        ");
        $stmt->execute($base_params);
        $result['grand'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $result['grand'] = ['error' => $e->getMessage()]; }
}

$result['success']   = true;
$result['date_from'] = $date_from;
$result['date_to']   = $date_to;
$result['generated_at'] = date('Y-m-d H:i:s');
echo json_encode($result);
