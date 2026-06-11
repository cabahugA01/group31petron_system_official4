<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['fuel_readings', 'fuel_daily_readings', 'fuel_adjustments'];
foreach ($tables as $t) {
    try {
        echo "=== $t columns ===\n";
        $q = $pdo->query("DESCRIBE $t");
        while($row = $q->fetch(PDO::FETCH_ASSOC)) {
            echo $row['Field'] . " - " . $row['Type'] . "\n";
        }
    } catch (Exception $e) {
        echo "Error describe $t: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
