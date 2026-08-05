<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$date_from = '2026-07-01';
$date_to = '2026-08-05';
$filters = [];

// Helper for table-qualified station_id clause
$st_params = ($station_id > 0) ? ['station_id' => $station_id] : [];
$st_clause = function(string $alias) use ($station_id): string {
    if ($station_id <= 0) return "";
    return " AND {$alias}.station_id = :station_id ";
};

$filter_pm     = $filters['payment_method'] ?? '';
$filter_ttype  = $filters['transaction_type'] ?? '';
$filter_cust   = trim($filters['customer'] ?? '');
$filter_mech   = trim($filters['mechanic'] ?? '');
$filter_status = $filters['status'] ?? '';

// 1. Merchandise
$m_where = " AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service') ";
$m_params = ['date_from' => $date_from, 'date_to' => $date_to];
$sql_merch = "SELECT 
                mt.transaction_id as receipt_no,
                DATE(mt.transaction_date) as date,
                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                COALESCE(mti.category, 'General') as category,
                COALESCE(mti.product_name, mt.item_sku, 'Merchandise Product') as product,
                COALESCE(mti.quantity, mt.quantity, 1) as quantity,
                COALESCE(mti.unit_price, mt.unit_price, 0) as unit_price,
                COALESCE(mti.subtotal, mt.total_amount, 0) as amount,
                COALESCE(mt.payment_method, 'Cash') as payment_method
              FROM merchandise_transactions mt
              LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id AND (mti.item_type IS NULL OR mti.item_type = 'merchandise')
              WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                {$st_clause('mt')}
                {$m_where}
              ORDER BY mt.transaction_date DESC";
$stmt_m = $pdo->prepare($sql_merch);
$stmt_m->execute(array_merge($m_params, $st_params));
$merchandise = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

// 2. Job Orders
$j_where = " AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service') ";
$j_params = ['date_from' => $date_from, 'date_to' => $date_to];
$sql_jo = "SELECT 
            COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) as jo_no,
            DATE(mt.transaction_date) as date,
            COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
            CONCAT(COALESCE(mt.job_order_vehicle_plate,'N/A'), ' - ', COALESCE(mt.job_order_vehicle_type,'Vehicle')) as vehicle,
            COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Unassigned') as mechanic,
            COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') as service,
            COALESCE(mt.subtotal_amount, 0) as labor_fee,
            COALESCE(mt.vat_amount, 0) as service_fee,
            GREATEST(COALESCE(mt.total_amount,0) - COALESCE(mt.subtotal_amount,0) - COALESCE(mt.vat_amount,0), 0) as parts_cost,
            COALESCE(mt.total_amount, 0) as total_amount,
            COALESCE(mt.payment_method, 'Cash') as payment_method,
            COALESCE(mt.workflow_status, mt.validation_status, 'Completed') as status
           FROM merchandise_transactions mt
           WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
             {$st_clause('mt')}
             {$j_where}
           ORDER BY mt.transaction_date DESC";
$stmt_j = $pdo->prepare($sql_jo);
$stmt_j->execute(array_merge($j_params, $st_params));
$job_orders = $stmt_j->fetchAll(PDO::FETCH_ASSOC);

echo "=== MERCHANDISE ROWS (" . count($merchandise) . ") ===\n";
print_r($merchandise);

echo "=== JOB ORDERS ROWS (" . count($job_orders) . ") ===\n";
print_r($job_orders);

// Payment summary
$pm_map = [];
foreach ($merchandise as $m) {
    $pm = $m['payment_method'] ?: 'Cash';
    if (!isset($pm_map[$pm])) $pm_map[$pm] = ['count' => 0, 'amount' => 0];
    $pm_map[$pm]['count']++;
    $pm_map[$pm]['amount'] += (float)$m['amount'];
}
foreach ($job_orders as $j) {
    $pm = $j['payment_method'] ?: 'Cash';
    if (!isset($pm_map[$pm])) $pm_map[$pm] = ['count' => 0, 'amount' => 0];
    $pm_map[$pm]['count']++;
    $pm_map[$pm]['amount'] += (float)$j['total_amount'];
}
echo "=== PAYMENT SUMMARY ===\n";
print_r($pm_map);

// Category summary
$cat_map = [];
foreach ($merchandise as $m) {
    $c = $m['category'] ?: 'General';
    if (!isset($cat_map[$c])) $cat_map[$c] = ['count' => 0, 'amount' => 0];
    $cat_map[$c]['count']++;
    $cat_map[$c]['amount'] += (float)$m['amount'];
}
echo "=== CATEGORY SUMMARY ===\n";
print_r($cat_map);

// Status summary
$status_counts = [
    'Completed Job Orders' => 0,
    'Released Vehicles'    => 0,
    'Pending Job Orders'   => 0,
    'Cancelled Job Orders' => 0
];
foreach ($job_orders as $j) {
    $st = strtolower($j['status']);
    if (str_contains($st, 'completed')) $status_counts['Completed Job Orders']++;
    elseif (str_contains($st, 'release')) $status_counts['Released Vehicles']++;
    elseif (str_contains($st, 'cancel') || str_contains($st, 'reject')) $status_counts['Cancelled Job Orders']++;
    else $status_counts['Pending Job Orders']++;
}
echo "=== STATUS SUMMARY ===\n";
print_r($status_counts);
