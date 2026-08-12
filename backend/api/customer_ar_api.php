<?php
/**
 * GET/POST /backend/api/customer_ar_api.php
 * Unified Customer Accounts Receivable API.
 * Actions:
 *   - list   : list all active AR records for station
 *   - detail : AR detail for one customer
 *   - record_payment : record a payment against an AR record
 */
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../transaction_schema_fix.php';
require_once __DIR__ . '/../audit_logging.php';

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

$raw    = file_get_contents('php://input');
$post   = $raw ? (json_decode($raw, true) ?? []) : [];
$post   = array_merge($_GET, $_POST, $post);
$action = trim($post['action'] ?? 'list');

// ── Ensure AR table columns ──────────────────────────────────────────────────
foreach ([
    "ALTER TABLE customer_accounts_receivable ADD COLUMN IF NOT EXISTS station_id INT NOT NULL DEFAULT 0",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (Exception $e) {}
}

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT
                car.id,
                car.customer_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''), c.name, 'Unknown Customer') AS customer_name,
                c.contact_number,
                car.transaction_id,
                car.or_number,
                car.total_amount,
                car.amount_paid,
                car.outstanding_balance,
                car.status,
                car.created_at,
                car.updated_at,
                mt.payment_method,
                mt.transaction_date,
                mt.transaction_type
            FROM customer_accounts_receivable car
            LEFT JOIN customers c ON c.id = car.customer_id
            LEFT JOIN merchandise_transactions mt ON (mt.id = car.transaction_db_id OR mt.transaction_id = car.transaction_id)
            WHERE car.station_id = ? 
              AND car.status != 'Voided'
              AND (mt.payment_method IS NULL OR LOWER(TRIM(mt.payment_method)) IN ('credit account', 'credit', 'ar', 'account receivable'))
            ORDER BY car.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = [
            'total_ar_amount'   => 0,
            'total_amount_paid' => 0,
            'total_outstanding' => 0,
            'active_accounts'   => 0
        ];
        foreach ($rows as &$row) {
            $row['total_amount']        = (float)$row['total_amount'];
            $row['amount_paid']         = (float)$row['amount_paid'];
            $row['outstanding_balance'] = (float)$row['outstanding_balance'];
            if (strtolower($row['status']) === 'active') {
                $totals['total_ar_amount']   += $row['total_amount'];
                $totals['total_amount_paid'] += $row['amount_paid'];
                $totals['total_outstanding'] += $row['outstanding_balance'];
                $totals['active_accounts']++;
            }
        }
        echo json_encode(['success'=>true, 'data'=>$rows, 'totals'=>$totals]);

    } elseif ($action === 'detail') {
        $customer_id = (int)($post['customer_id'] ?? 0);
        if (!$customer_id) { echo json_encode(['success'=>false,'error'=>'Customer ID required']); exit; }

        $stmt = $pdo->prepare("
            SELECT
                car.*,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)),''), c.name, 'Unknown') AS customer_name,
                mt.payment_method, mt.transaction_date, mt.transaction_type,
                mt.validation_status, mt.total_amount AS txn_total
            FROM customer_accounts_receivable car
            LEFT JOIN customers c ON c.id = car.customer_id
            LEFT JOIN merchandise_transactions mt ON (mt.id = car.transaction_db_id OR mt.transaction_id = car.transaction_id)
            WHERE car.customer_id = ? 
              AND car.station_id = ? 
              AND car.status != 'Voided'
              AND (mt.payment_method IS NULL OR LOWER(TRIM(mt.payment_method)) IN ('credit account', 'credit', 'ar', 'account receivable'))
            ORDER BY car.created_at DESC
        ");
        $stmt->execute([$customer_id, $station_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['total_amount'=>0, 'amount_paid'=>0, 'outstanding_balance'=>0];
        foreach ($records as $r) {
            $summary['total_amount']        += (float)$r['total_amount'];
            $summary['amount_paid']         += (float)$r['amount_paid'];
            $summary['outstanding_balance'] += (float)$r['outstanding_balance'];
        }
        echo json_encode(['success'=>true, 'records'=>$records, 'summary'=>$summary]);

    } elseif ($action === 'record_payment') {
        if (!in_array($role, ['manager','admin','superadmin'])) {
            http_response_code(403); echo json_encode(['success'=>false,'error'=>'Manager access required']); exit;
        }
        $ar_id       = (int)($post['ar_id'] ?? 0);
        $amount      = (float)($post['amount'] ?? 0);
        $pay_method  = trim($post['payment_method'] ?? 'Cash');
        $remarks     = trim($post['remarks'] ?? '');
        if (!$ar_id || $amount <= 0) { echo json_encode(['success'=>false,'error'=>'AR ID and amount required']); exit; }

        $pdo->beginTransaction();

        $ar = $pdo->prepare("SELECT * FROM customer_accounts_receivable WHERE id = ? AND station_id = ? LIMIT 1");
        $ar->execute([$ar_id, $station_id]);
        $rec = $ar->fetch(PDO::FETCH_ASSOC);
        if (!$rec) { $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>'AR record not found']); exit; }

        $new_paid        = round((float)$rec['amount_paid'] + $amount, 2);
        $new_outstanding = max(0, round((float)$rec['total_amount'] - $new_paid, 2));
        $new_status      = ($new_outstanding <= 0) ? 'Settled' : 'Active';

        $pdo->prepare("
            UPDATE customer_accounts_receivable 
            SET amount_paid = ?, outstanding_balance = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$new_paid, $new_outstanding, $new_status, $ar_id]);

        // Also update merchandise_transactions payment_status
        if (!empty($rec['transaction_db_id'])) {
            $new_pay_status = ($new_outstanding <= 0) ? 'Paid' : 'Partial Payment';
            try {
                $pdo->prepare("UPDATE merchandise_transactions SET payment_status = ?, amount_paid = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_pay_status, $new_paid, (int)$rec['transaction_db_id']]);
            } catch (Exception $e) {}
        }

        log_structured_audit([
            'user_id'        => $me['id'],
            'user_role'      => $role,
            'action'         => 'AR Payment Recorded',
            'module'         => 'Accounts Receivable',
            'transaction_id' => $rec['transaction_id'] ?? '',
            'or_number'      => $rec['or_number'] ?? '',
            'old_values'     => ['amount_paid'=>(float)$rec['amount_paid'], 'outstanding_balance'=>(float)$rec['outstanding_balance']],
            'new_values'     => ['amount_paid'=>$new_paid, 'outstanding_balance'=>$new_outstanding, 'payment_method'=>$pay_method],
            'reason'         => "AR Payment: {$pay_method}" . ($remarks ? " - {$remarks}" : ''),
            'station_id'     => $station_id
        ]);

        $pdo->commit();
        echo json_encode([
            'success'          => true,
            'message'          => 'Payment recorded successfully.',
            'new_amount_paid'  => $new_paid,
            'outstanding'      => $new_outstanding,
            'status'           => $new_status
        ]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Unknown action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('customer_ar_api: ' . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Database error: '.$e->getMessage()]);
}
