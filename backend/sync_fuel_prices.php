<?php
/**
 * Fix: fuel_types.price_per_liter is shared across all stations.
 * Use station_id 1253 (main operational station) as the source for fuel_types.
 * fuel_pricing (per-station) is already correct after previous sync.
 */
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: text/plain');

// Use station 1253 as the canonical source for fuel_types (shared table)
$main_station = 1253;

$rows = $pdo->prepare("SELECT fuel_type_id, price_per_liter FROM fuel_inventory WHERE station_id = ? AND price_per_liter > 0 AND fuel_type_id IS NOT NULL");
$rows->execute([$main_station]);
$main_prices = $rows->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($main_prices as $r) {
    $n = $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?");
    $n->execute([$r['price_per_liter'], $r['fuel_type_id']]);
    $updated += $n->rowCount();
}

echo "fuel_types updated from station {$main_station}: {$updated} rows\n\n";

echo "=== Final Verification ===\n";
$verify = $pdo->query("
    SELECT fi.fuel_type, fi.station_id, fi.price_per_liter AS fi_price, ft.price_per_liter AS ft_price,
           (SELECT fp2.price_per_liter FROM fuel_pricing fp2 WHERE fp2.station_id = fi.station_id AND fp2.fuel_type_id = fi.fuel_type_id AND fp2.is_active = 1 LIMIT 1) AS fp_price
    FROM fuel_inventory fi
    LEFT JOIN fuel_types ft ON ft.id = fi.fuel_type_id
    WHERE fi.price_per_liter > 0
    ORDER BY fi.fuel_type, fi.station_id
")->fetchAll(PDO::FETCH_ASSOC);

printf("%-20s | %-10s | %-10s | %-10s | %-10s | %s\n", 'fuel_type', 'station', 'fi.price', 'ft.price', 'fp.price', 'STATUS');
echo str_repeat('-', 80) . "\n";
foreach ($verify as $v) {
    // fi.price must == fp.price (per-station pricing) — that's what matters
    $fp_ok = ($v['fi_price'] == $v['fp_price']) ? 'OK' : 'FP-MISMATCH';
    printf("%-20s | %-10s | %-10s | %-10s | %-10s | %s\n",
        $v['fuel_type'], $v['station_id'],
        $v['fi_price'], $v['ft_price'] ?? '-', $v['fp_price'] ?? '-', $fp_ok);
}
echo "\nNote: ft.price is shared (single value per fuel type). fi.price = fp.price per station is what matters.\n";
