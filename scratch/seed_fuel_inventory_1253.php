<?php
/**
 * Seed initial fuel inventory levels for station 1253 (Judy & Edgar's station).
 * Sets realistic opening stock levels so the fuel management pages show real data.
 *
 * Run once: php scratch/seed_fuel_inventory_1253.php
 */
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;

// Seed realistic starting levels (in liters) and prices
$seeds = [
    'Diesel'      => ['level' => 32500.00, 'price' => 64.35],
    'Turbo Diesel'=> ['level' => 28000.00, 'price' => 68.10],
    'XCS Plus'    => ['level' => 18500.00, 'price' => 71.25],
    'XTRA UNL'    => ['level' => 15000.00, 'price' => 68.50],
    'Kerosene'    => ['level' =>  9000.00, 'price' => 58.90],
];

$updated = 0;
foreach ($seeds as $fuel_type => $data) {
    $stmt = $pdo->prepare("
        UPDATE fuel_inventory
        SET current_level     = ?,
            current_stock     = ?,
            price_per_liter   = ?,
            last_updated      = NOW(),
            status            = 'Normal'
        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
    ");
    $stmt->execute([
        $data['level'],
        $data['level'],
        $data['price'],
        $station_id,
        $fuel_type,
    ]);
    $rows = $stmt->rowCount();
    echo "  $fuel_type: updated $rows row(s) → level={$data['level']} L, price=₱{$data['price']}\n";
    $updated += $rows;
}

echo "\nDone. Total rows updated: $updated\n";

// Verify
echo "\n=== Verification ===\n";
$rows = $pdo->query("SELECT fuel_type, current_level, current_stock, price_per_liter, status FROM fuel_inventory WHERE station_id = $station_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    printf("  %-14s  level=%-10s  stock=%-10s  price=%s\n",
        $r['fuel_type'],
        number_format($r['current_level'], 2),
        number_format($r['current_stock'], 2),
        number_format($r['price_per_liter'], 2)
    );
}
