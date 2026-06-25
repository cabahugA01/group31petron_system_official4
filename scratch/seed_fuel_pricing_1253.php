<?php
/**
 * Seed fuel_pricing for station 1253 so price_per_liter shows correctly on
 * the Fuel Inventory pages (staff_inventory_fuel.php / manager_inventory_fuel.php).
 *
 * Run once: php scratch/seed_fuel_pricing_1253.php
 */
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;

// Check fuel_types table first
$fuel_types = $pdo->query("SELECT id, name FROM fuel_types")->fetchAll(PDO::FETCH_ASSOC);
echo "=== fuel_types table ===\n";
foreach ($fuel_types as $ft) {
    echo "  id={$ft['id']}  name={$ft['name']}\n";
}

// Map our fuel names to fuel_type_ids
$ft_map = [];
foreach ($fuel_types as $ft) {
    $ft_map[strtolower(trim($ft['name']))] = (int)$ft['id'];
}

$prices = [
    'Diesel'      => 64.35,
    'Turbo Diesel'=> 68.10,
    'XCS Plus'    => 71.25,
    'XTRA UNL'    => 68.50,
    'Kerosene'    => 58.90,
];

echo "\n=== Seeding fuel_pricing for station $station_id ===\n";
foreach ($prices as $fuel_name => $price) {
    $ft_id = $ft_map[strtolower(trim($fuel_name))] ?? null;
    if (!$ft_id) {
        echo "  SKIP $fuel_name – no matching fuel_type_id\n";
        continue;
    }

    // Deactivate existing active prices for this fuel/station
    $pdo->prepare("UPDATE fuel_pricing SET is_active=0 WHERE station_id=? AND fuel_type_id=? AND is_active=1")
        ->execute([$station_id, $ft_id]);

    // Insert new active price
    $pdo->prepare("
        INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_at)
        VALUES (?, ?, ?, CURDATE(), 1, NOW())
        ON DUPLICATE KEY UPDATE price_per_liter=VALUES(price_per_liter), effective_date=VALUES(effective_date), is_active=1, created_at=NOW()
    ")->execute([$station_id, $ft_id, $price]);

    echo "  OK  $fuel_name (ft_id=$ft_id) → ₱$price\n";
}

echo "\n=== Verification from fuel_pricing ===\n";
$rows = $pdo->query("
    SELECT ft.name, fp.price_per_liter, fp.effective_date, fp.is_active
    FROM fuel_pricing fp
    JOIN fuel_types ft ON fp.fuel_type_id = ft.id
    WHERE fp.station_id = $station_id AND fp.is_active = 1
    ORDER BY ft.name
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    printf("  %-14s  ₱%-8s  effective: %s\n", $r['name'], $r['price_per_liter'], $r['effective_date']);
}
echo "\nDone.\n";
