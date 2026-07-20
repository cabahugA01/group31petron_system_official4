<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "<pre>";

$station_id = 1253; // Carmen station ID
$date_from = date('Y-m-d', strtotime('-365 days'));
$date_to   = date('Y-m-d');
$search = '';
$f_shift = '';
$f_staff = '';
$f_type = '';
$f_pay = '';
$f_status = '';

$mt_cols = [];
try {
    foreach($pdo->query("SHOW COLUMNS FROM `merchandise_transactions`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $mt_cols[strtolower($c['Field'])] = true;
    }
} catch(Exception $e){}

$mt_date = isset($mt_cols['transaction_date'])
    ? "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END"
    : 'mt.created_at';
$mt_stat = isset($mt_cols['validation_status']) ? 'mt.validation_status' : "'Approved'";

$u_cols = [];
try {
    foreach($pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $u_cols[strtolower($c['Field'])] = true;
    }
} catch(Exception $e){}

$assigned_shift_col = isset($u_cols['assigned_shift']) ? 'u.assigned_shift' : (isset($u_cols['shift_assignment']) ? 'u.shift_assignment' : "''");

$mt_shift = "CASE WHEN LOWER(TRIM(COALESCE(mt.shift_period, mt.shift_name, $assigned_shift_col, ''))) IN ('first', 'shift 1', 'shift1') THEN 'Shift 1' WHEN LOWER(TRIM(COALESCE(mt.shift_period, mt.shift_name, $assigned_shift_col, ''))) IN ('second', 'shift 2', 'shift2') THEN 'Shift 2' ELSE COALESCE(NULLIF(TRIM(mt.shift_period),''), NULLIF(TRIM(mt.shift_name),''), NULLIF(TRIM($assigned_shift_col),''), 'N/A') END";
$mt_pay   = isset($mt_cols['payment_method']) ? "COALESCE(mt.payment_method,'Cash')" : "'Cash'";
$mt_pstat = isset($mt_cols['payment_status']) ? "COALESCE(mt.payment_status,'')" : "''";
$void_reason_col = isset($mt_cols['void_reason']) ? 'mt.void_reason' : 'NULL';
$adj_reason_col = isset($mt_cols['adjustment_reason']) ? 'mt.adjustment_reason' : 'NULL';
$mgr_remarks_col = isset($mt_cols['manager_remarks']) ? 'mt.manager_remarks' : 'NULL';

$where  = "WHERE mt.station_id=?";
$params = [$station_id];

if($date_from !== '' && $date_to !== '') {
    $where .= " AND DATE($mt_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

$veh_col = isset($mt_cols['vehicle_plate']) ? 'COALESCE(mt.vehicle_plate,"—")' : '"—"';
$staff_col = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),u.username,'Unknown')";

try {
    $sql = "SELECT mt.id as txn_db_id, mt.transaction_id, COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') as customer,
        COALESCE(mt.transaction_type,'Merchandise') as txn_type,
        $veh_col as vehicle,
        mt.total_amount as amount, $mt_pay as payment_method,
        $mt_shift as shift, $staff_col as staff_name,
        $mt_pstat as payment_status, $mt_date as txn_date,
        $mt_stat as validation_status,
        $void_reason_col as void_reason,
        $adj_reason_col as adjustment_reason,
        $mgr_remarks_col as manager_remarks,
        GROUP_CONCAT(CONCAT(mti.product_name, '::', COALESCE(mti.size_variant,''), '::', mti.quantity) ORDER BY mti.id SEPARATOR '||') as items,
        COALESCE(NULLIF(TRIM(mt.job_order_service),''), '') as service_type
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id=mt.staff_id
        LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id=mt.id
        $where GROUP BY mt.id ORDER BY $mt_date DESC LIMIT 500";
    
    echo "SQL Query:\n$sql\n\nParams: " . print_r($params, true) . "\n";
    
    $s=$pdo->prepare($sql);
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    echo "Success! Row count: " . count($rows) . "\n";
} catch(Exception $e){
    echo "QUERY FAILED: " . $e->getMessage() . "\n";
}
echo "</pre>";
