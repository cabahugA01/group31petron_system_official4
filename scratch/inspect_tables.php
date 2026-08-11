<?php
require_once __DIR__ . '/../public/db_connect.php';

function check_table($pdo, $table) {
    echo "=== TABLE: $table ===\n";
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "{$c['Field']} - {$c['Type']} - NULL:{$c['Null']} - DEF:{$c['Default']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

check_table($pdo, 'transaction_requests');
check_table($pdo, 'job_orders');
check_table($pdo, 'merchandise_transactions');
