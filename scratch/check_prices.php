<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

foreach ($tables as $t) {
    if (strpos($t, 'pump') !== false || strpos($t, 'tank') !== false || strpos($t, 'nozzle') !== false) {
        echo "\n=== DESCRIBE $t ===\n";
        print_r($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC));
        echo "\n=== SELECT FROM $t ===\n";
        print_r($pdo->query("SELECT * FROM $t LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
    }
}
