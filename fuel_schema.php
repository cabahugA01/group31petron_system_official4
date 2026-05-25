<?php
require_once __DIR__ . '/public/db_connect.php';
$tables = ['fuel_transactions','fuel_readings','fuel_daily_readings','fuel_meter_readings','merchandise_transactions'];
foreach($tables as $t) {
    try {
        $r = $pdo->query("SHOW COLUMNS FROM $t");
        echo "=== $t ===\n";
        foreach($r->fetchAll(PDO::FETCH_ASSOC) as $c) echo "  ".$c['Field']." | ".$c['Type']." | default:".$c['Default']."\n";
        echo "\n";
        // Sample 2 rows
        $r2 = $pdo->query("SELECT * FROM $t ORDER BY id DESC LIMIT 2");
        foreach($r2->fetchAll(PDO::FETCH_ASSOC) as $row) { echo "  ROW: "; print_r($row); }
    } catch(Exception $e) { echo "=== $t === ERROR: ".$e->getMessage()."\n"; }
}
