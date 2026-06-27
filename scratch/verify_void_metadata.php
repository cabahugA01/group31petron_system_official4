<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== VERIFYING VOIDED QUERY METADATA FETCH ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT 
            vt.id as void_id, vt.transaction_id, vt.transaction_type,
            COALESCE(vt.customer_name,'Walk-in') as customer,
            vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
            vt.fields_changed,
            COALESCE(NULLIF(vt.job_order_no,''), NULLIF(mt.job_order_id,'')) AS job_order_no,
            COALESCE(NULLIF(vt.vehicle_plate,''), NULLIF(mt.job_order_vehicle_plate,'')) AS vehicle_plate,
            COALESCE(NULLIF(vt.payment_method,''), NULLIF(mt.payment_method,''), 'Cash') AS payment_method
        FROM voided_transactions vt
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
        ORDER BY vt.void_date DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $fields = !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : [];
        $jo = !empty($r['job_order_no']) ? $r['job_order_no'] : ($fields['job_order_no'] ?? '—');
        if ($jo !== '—' && !str_starts_with($jo, 'JO-')) $jo = 'JO-' . $jo;
        $plate = !empty($r['vehicle_plate']) ? $r['vehicle_plate'] : ($fields['vehicle_plate'] ?? '—');
        $pay = !empty($r['payment_method']) ? $r['payment_method'] : ($fields['payment_method'] ?? 'Cash');
        echo "VOID-{$r['void_id']} | Txn: {$r['transaction_id']} | JO: {$jo} | Plate: {$plate} | Pay: {$pay}\n";
    }
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
