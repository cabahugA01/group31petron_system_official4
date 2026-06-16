<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['merchandise_transactions', 'job_orders'];
foreach ($tables as $t) {
    echo "Table: $t\n";
    try {
        $q = $pdo->query("SHOW COLUMNS FROM `$t`");
        if ($q) {
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
                echo "  {$c['Field']} ({$c['Type']})\n";
            }
        } else {
            echo "  (No columns or table does not exist)\n";
        }
    } catch(Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
