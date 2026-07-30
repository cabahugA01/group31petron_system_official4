<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "All tables in database:\n";
foreach ($tables as $table) {
    if (strpos($table, 'fuel') !== false || strpos($table, 'reading') !== false || strpos($table, 'pump') !== false || strpos($table, 'shift') !== false || strpos($table, 'report') !== false || strpos($table, 'transaction') !== false || strpos($table, 'sales') !== false) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  - $table: $count rows\n";
    }
}
?>
