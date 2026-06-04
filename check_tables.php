<?php
require 'public/db_connect.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    if (strpos($table, 'product') !== false || 
        strpos($table, 'invent') !== false || 
        strpos($table, 'deliver') !== false || 
        strpos($table, 'purchase') !== false || 
        strpos($table, 'stock') !== false ||
        strpos($table, 'batch') !== false ||
        strpos($table, 'fuel') !== false) {
        
        echo "=== TABLE: $table ===\n";
        $columns = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $c) {
            echo "  {$c['Field']} - {$c['Type']} - " . ($c['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . " - {$c['Key']} - {$c['Default']}\n";
        }
        echo "\n";
    }
}
