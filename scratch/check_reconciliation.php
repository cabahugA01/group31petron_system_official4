<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['fuel_readings', 'fuel_daily_readings', 'fuel_adjustments', 'fuel_transactions', 'fuel_deliveries'];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "$t total count: $count\n";
        if ($count > 0) {
            $station_count = $pdo->query("SELECT COUNT(*) FROM $t WHERE station_id = 1253")->fetchColumn();
            echo "$t station 1253 count: $station_count\n";
            $sample = $pdo->query("SELECT * FROM $t LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            echo "Sample from $t:\n";
            print_r($sample);
        }
    } catch (Exception $e) {
        echo "Error on $t: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
