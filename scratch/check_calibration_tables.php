<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['fuel_calibration', 'fuel_calibration_defaults', 'fuel_calibration_records'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    try {
        foreach ($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "  " . $r['Field'] . " | " . $r['Type'] . "\n";
        }
        $rows = $pdo->query("SELECT * FROM $t LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        echo "  Rows:\n";
        print_r($rows);
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
