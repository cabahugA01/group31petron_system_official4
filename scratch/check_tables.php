<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "- $t\n";
}

echo "\n=== DESCRIBE fuel_adjustments ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_adjustments")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== DESCRIBE fuel_transactions ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_transactions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
