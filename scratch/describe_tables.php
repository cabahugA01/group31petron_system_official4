<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['pump_calibration_history','fuel_inventory'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    try {
        foreach ($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC) as $r)
            echo $r['Field'] . ' | ' . $r['Type'] . "\n";
    } catch (Exception $e) { echo "ERROR: ".$e->getMessage()."\n"; }
    echo "\n";
}
