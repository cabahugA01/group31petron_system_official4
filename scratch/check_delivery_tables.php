<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "--- deliveries_oversight columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE deliveries_oversight")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- Show tables like delivery ---\n";
try {
    $tables = $pdo->query("SHOW TABLES LIKE '%deliver%'")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- Show tables like purchase ---\n";
try {
    $tables = $pdo->query("SHOW TABLES LIKE '%purchase%'")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
