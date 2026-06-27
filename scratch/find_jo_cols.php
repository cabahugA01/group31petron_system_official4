<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== SEARCHING FOR JOB ORDER AND PLATE COLUMNS IN ALL TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    try {
        $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $matched = [];
        foreach ($cols as $c) {
            if (preg_match('/job_order|plate|vehicle|payment/i', $c['Field'])) {
                $matched[] = $c['Field'];
            }
        }
        if (!empty($matched)) {
            echo "Table `$t`: " . implode(', ', $matched) . "\n";
        }
    } catch(Exception $e) {}
}
