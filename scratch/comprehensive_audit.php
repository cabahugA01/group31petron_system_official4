<?php
/**
 * Comprehensive Audit of all Transaction Modules for Staff, Manager, and Admin
 */
require __DIR__ . '/../public/db_connect.php';

$files = [
    // Staff
    'public/staff_transactions_hub.php',
    'public/voided_transactions.php',
    // Manager
    'public/manager_transaction_monitoring.php',
    'public/manager_voided_transactions.php',
    'public/manager_fuel_transactions.php',
    // Admin
    'public/admin_transaction_overview.php',
    'public/admin_transaction_adjustments.php',
    'public/admin_voided_transactions.php',
    'public/admin_transactions_oversight.php'
];

echo "=== 1. PHP SYNTAX CHECK ===\n";
foreach ($files as $f) {
    $full = __DIR__ . '/../' . $f;
    if (!file_exists($full)) {
        echo "MISSING FILE: $f\n";
        continue;
    }
    $cmd = "C:\\xampp\\php\\php.exe -l " . escapeshellarg($full);
    $out = shell_exec($cmd);
    if (strpos($out, 'No syntax errors detected') !== false) {
        echo "[OK] Syntax: $f\n";
    } else {
        echo "[ERROR] Syntax: $f -> $out\n";
    }
}

echo "\n=== 2. DATABASE RECONCILIATION CHECK ===\n";
try {
    $mt_count = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions")->fetchColumn();
    $vt_count = $pdo->query("SELECT COUNT(*) FROM voided_transactions")->fetchColumn();
    $ta_count = $pdo->query("SELECT COUNT(*) FROM transaction_adjustments")->fetchColumn();
    echo "merchandise_transactions count: $mt_count\n";
    echo "voided_transactions count: $vt_count\n";
    echo "transaction_adjustments count: $ta_count\n";
} catch(Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}

echo "\n=== 3. VERIFYING ALL ADMIN/MANAGER/STAFF SQL QUERIES ===\n";

// Test Admin Overview Query
echo "--- Testing admin_transaction_overview query ---\n";
try {
    $stmt = $pdo->query("SELECT mt.*, 
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '), u.username, 'System') as staff_name
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        ORDER BY mt.transaction_date DESC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($rows) . " rows for Admin Overview.\n";
} catch(Exception $e) {
    echo "[ERROR] Admin Overview query: " . $e->getMessage() . "\n";
}

// Test Admin Adjustments Query
echo "--- Testing admin_transaction_adjustments query ---\n";
try {
    $stmt = $pdo->query("SELECT ta.id as adj_id, ta.transaction_id, COALESCE(ta.customer_name,'Walk-in') as customer,
        ta.transaction_type, ta.original_amount, ta.updated_amount, ta.amount_difference,
        ta.adjustment_reason, ta.manager_remarks, ta.adjustment_date, ta.fields_changed,
        mt.job_order_id, mt.job_order_vehicle_plate, mt.payment_method, mt.workflow_status,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),u.username,'Unknown') as adjusted_by_name,
        (SELECT GROUP_CONCAT(product_name SEPARATOR ', ') FROM merchandise_transaction_items WHERE transaction_id = mt.id) AS item_names
        FROM transaction_adjustments ta 
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = ta.transaction_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users u ON u.id=ta.adjusted_by
        ORDER BY ta.adjustment_date DESC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($rows) . " rows for Admin Adjustments.\n";
} catch(Exception $e) {
    echo "[ERROR] Admin Adjustments query: " . $e->getMessage() . "\n";
}

// Test Manager Voided Query
echo "--- Testing manager_voided_transactions query ---\n";
try {
    $stmt = $pdo->query("SELECT vt.id as void_id, vt.transaction_id, vt.transaction_type,
        COALESCE(vt.customer_name,'Walk-in') as customer,
        vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
        vt.fields_changed,
        COALESCE(NULLIF(vt.job_order_no,''), NULLIF(mt.job_order_id,'')) AS job_order_no,
        COALESCE(NULLIF(vt.vehicle_plate,''), NULLIF(mt.job_order_vehicle_plate,'')) AS vehicle_plate,
        COALESCE(NULLIF(vt.payment_method,''), NULLIF(mt.payment_method,''), 'Cash') AS payment_method,
        COALESCE(NULLIF(vt.voided_by_name,''), NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '), u.username, 'Unknown') as voided_by_name,
        (SELECT GROUP_CONCAT(mti.product_name SEPARATOR ', ') FROM merchandise_transactions mt2
         INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt2.id
         WHERE mt2.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci) AS item_names
        FROM voided_transactions vt 
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users u ON u.id=vt.voided_by
        ORDER BY vt.void_date DESC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($rows) . " rows for Manager Voided.\n";
} catch(Exception $e) {
    echo "[ERROR] Manager Voided query: " . $e->getMessage() . "\n";
}

echo "\nALL TESTS FINISHED!\n";
