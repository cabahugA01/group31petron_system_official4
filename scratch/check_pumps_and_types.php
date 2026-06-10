<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['fuel_pumps', 'fuel_types', 'fuel_inventory'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    try {
        foreach ($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "  " . $r['Field'] . " | " . $r['Type'] . "\n";
        }
        $rows = $pdo->query("SELECT * FROM $t LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        echo "  Rows Count: " . count($rows) . "\n";
        print_r($rows);
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
