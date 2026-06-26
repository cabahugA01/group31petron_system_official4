<?php
require __DIR__ . '/../public/db_connect.php';
$tables = ['fuel_deliveries','fuel_transactions','fuel_adjustments'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    $s = $pdo->query("SHOW COLUMNS FROM `$t`");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";
    }
}
