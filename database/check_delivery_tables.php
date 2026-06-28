<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MERCHANDISE DELIVERY SYSTEM CHECK ===\n\n";

// Check which delivery tables exist
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

$delivery_tables = [
    'deliveries_oversight',
    'merchandise_deliveries',
    'received_items',
    'receiving_batches'
];

echo "TABLE EXISTENCE:\n";
foreach ($delivery_tables as $table) {
    if (in_array($table, $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "  ✓ $table EXISTS ($count records)\n";
        
        // Show structure
        echo "    Columns: ";
        $cols = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        echo implode(', ', $cols) . "\n";
    } else {
        echo "  ✗ $table DOES NOT EXIST\n";
    }
}

echo "\n";

// Check where staff saves merchandise
echo "STAFF MERCHANDISE TRANSACTION FLOW:\n";
if (in_array('merchandise_transactions', $tables)) {
    $count = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions")->fetchColumn();
    echo "  ✓ merchandise_transactions table exists ($count records)\n";
} else {
    echo "  ✗ merchandise_transactions table DOES NOT exist\n";
}

echo "\nMANAGER VALIDATION PAGE EXPECTS:\n";
echo "  - Table: deliveries_oversight\n";
echo "  - Filter: WHERE delivery_type='merchandise'\n\n";

// Check if there's any data in deliveries_oversight
if (in_array('deliveries_oversight', $tables)) {
    $merch_count = $pdo->query("SELECT COUNT(*) FROM deliveries_oversight WHERE delivery_type='merchandise'")->fetchColumn();
    echo "CURRENT MERCHANDISE DELIVERIES IN deliveries_oversight: $merch_count\n\n";
    
    if ($merch_count > 0) {
        echo "Sample records:\n";
        $sample = $pdo->query("SELECT * FROM deliveries_oversight WHERE delivery_type='merchandise' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        print_r($sample);
    }
}

echo "\n=== DIAGNOSIS ===\n";
echo "Issue: Staff encodes merchandise transactions into 'merchandise_transactions' table\n";
echo "       but Manager page looks for 'deliveries_oversight' table.\n";
echo "Solution: Create a trigger or modify staff encoding to also insert into deliveries_oversight.\n";
?>
