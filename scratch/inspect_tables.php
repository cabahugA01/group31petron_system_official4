<?php
require_once __DIR__ . '/../public/db_connect.php';

$output = "DATABASE TABLES AND COLUMNS:\n\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $output .= "=== TABLE: $t ===\n";
    $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        $output .= "  {$c['Field']} | {$c['Type']} | Null:{$c['Null']} | Default:{$c['Default']}\n";
    }
    $output .= "\n";
}
file_put_contents(__DIR__ . '/db_schema_utf8.txt', $output);
echo "SUCCESS";
