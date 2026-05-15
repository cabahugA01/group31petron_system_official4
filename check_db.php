<?php
require_once 'public/db_connect.php';

$errors = [];
$done = [];

// 1. Add purchase_request_id column to stock_requests if missing
try {
    $cols = $pdo->query('SHOW COLUMNS FROM stock_requests')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_request_id', $cols)) {
        $pdo->exec("ALTER TABLE stock_requests ADD COLUMN purchase_request_id VARCHAR(50) NULL DEFAULT NULL AFTER approved_price");
        $done[] = "Added purchase_request_id column to stock_requests";
    } else {
        $done[] = "purchase_request_id column already exists";
    }
} catch (Exception $e) {
    $errors[] = "purchase_request_id: " . $e->getMessage();
}

// 2. Expand stock_requests.status enum to include new statuses
try {
    $pdo->exec("ALTER TABLE stock_requests MODIFY COLUMN status ENUM('Pending','Approved','Validated','Forwarded to Admin','Approved PO','Rejected') DEFAULT 'Pending'");
    $done[] = "Updated stock_requests.status enum";
} catch (Exception $e) {
    $errors[] = "SR status enum: " . $e->getMessage();
}

// 3. Expand purchase_orders.status enum to include 'Approved PO'
try {
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received','Cancelled','Pending Admin Validation','Official','Approved PO') DEFAULT 'Pending Admin Validation'");
    $done[] = "Updated purchase_orders.status enum";
} catch (Exception $e) {
    $errors[] = "PO status enum: " . $e->getMessage();
}

echo "=== DONE ===\n";
foreach ($done as $d) echo "  OK: $d\n";
if ($errors) {
    echo "=== ERRORS ===\n";
    foreach ($errors as $e) echo "  ERR: $e\n";
}

// Verify
$r = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
echo "\nPO status: " . $r['Type'] . "\n";
$sr = $pdo->query("SHOW COLUMNS FROM stock_requests LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
echo "SR status: " . $sr['Type'] . "\n";
$cols2 = $pdo->query('SHOW COLUMNS FROM stock_requests')->fetchAll(PDO::FETCH_ASSOC);
echo "SR cols: " . implode(', ', array_column($cols2, 'Field')) . "\n";
