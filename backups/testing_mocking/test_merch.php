<?php
require 'public/db_connect.php';
try {
    $station_id = 1253; // assuming from previous tests
    $staff_id = 21; // assuming Amie
    $mh_where  = "WHERE mt.station_id = ? AND mt.staff_id = ?";
    $mh_params = [$station_id, $staff_id];
    
    $mh_cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $mh_cols[strtolower($cr['Field'])] = true;
    }
    
    $mh_date_col   = isset($mh_cols['transaction_date']) ? 'mt.transaction_date' : 'mt.created_at';
    $mh_status_col = isset($mh_cols['validation_status']) ? 'mt.validation_status' : (isset($mh_cols['status']) ? 'mt.status' : "'Pending'");
    $mh_txnid_col  = isset($mh_cols['transaction_id'])   ? 'mt.transaction_id'   : 'mt.id';

    $stmt_mh = $pdo->prepare("
        SELECT mt.id,
               $mh_txnid_col AS transaction_id,
               mt.customer_name,
               mt.total_amount,
               mt.payment_method,
               $mh_date_col  AS transaction_date,
               $mh_status_col AS status,
               mt.shift_name,
               mt.shift_period
        FROM merchandise_transactions mt
        $mh_where
        ORDER BY $mh_date_col DESC
        LIMIT 10 OFFSET 0
    ");
    $stmt_mh->execute($mh_params);
    $mh_recent = $stmt_mh->fetchAll(PDO::FETCH_ASSOC);
    echo "Count: " . count($mh_recent) . "\n";
    if (empty($mh_recent)) echo "EMPTY ARRAY\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
