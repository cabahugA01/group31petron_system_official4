<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== TEST JOINING MERCHANDISE_TRANSACTIONS WITH VOIDED_TRANSACTIONS ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT 
            vt.id as void_id, vt.transaction_id, vt.customer_name as vt_cust, vt.amount, vt.void_reason,
            vt.job_order_no as vt_jo, vt.vehicle_plate as vt_plate, vt.payment_method as vt_pay,
            mt.job_order_id as mt_jo, mt.job_order_vehicle_plate as mt_plate, mt.payment_method as mt_pay, mt.customer_name as mt_cust,
            COALESCE(NULLIF(vt.job_order_no,''), NULLIF(mt.job_order_id,''), '—') AS final_jo,
            COALESCE(NULLIF(vt.vehicle_plate,''), NULLIF(mt.job_order_vehicle_plate,''), '—') AS final_plate,
            COALESCE(NULLIF(vt.payment_method,''), NULLIF(mt.payment_method,''), 'Cash') AS final_payment
        FROM voided_transactions vt
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
