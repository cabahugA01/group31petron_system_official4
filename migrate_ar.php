<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

$columns = [
    'customer_id' => 'INT(11) NULL AFTER station_id',
    'sale_id' => 'INT(11) NULL AFTER customer_id',
    'job_order_id' => 'INT(11) NULL AFTER sale_id'
];

foreach ($columns as $col => $definition) {
    try {
        $pdo->query("SELECT $col FROM accounts_receivable LIMIT 0");
        echo "Column '$col' already exists.\n";
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE accounts_receivable ADD COLUMN $col $definition");
            echo "Successfully added column '$col'.\n";
        } catch (Exception $ex) {
            echo "Error adding column '$col': " . $ex->getMessage() . "\n";
        }
    }
}
