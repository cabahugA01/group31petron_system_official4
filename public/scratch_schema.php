<?php
require_once __DIR__ . '/db_connect.php';  $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$schema = [];
foreach ($tables as $table) {  $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);  $colNames = array_column($cols, 'Field');  $schema[$table] = implode(", ", $colNames);
}  foreach ($schema as $table => $cols) {  echo "TABLE: $table\nCOLS: $cols\n\n";
}
