<?php
/**
 * Final validation: simulates what staff_inventory_fuel.php and
 * manager_inventory_fuel.php fetch for station 1253.
 */
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;

echo "=== 1. fuel_inventory lookup ===\n";
$fi_lookup = [];
$s = $pdo->prepare("SELECT fuel_type, current_level, current_stock, capacity, price_per_liter, status, last_updated FROM fuel_inventory WHERE station_id = ?");
$s->execute([$station_id]);
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    printf("  %-14s  level=%-10s  capacity=%-8s  price=%-6s  status=%s\n",
        $row['fuel_type'],
        number_format($row['current_level'], 2),
        number_format($row['capacity'], 0),
        number_format($row['price_per_liter'], 2),
        $row['status']
    );
}

echo "\n=== 2. fuel_pricing lookup ===\n";
$s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id=ft.id WHERE fp.station_id=? AND fp.is_active=1 ORDER BY fp.effective_date DESC");
$s->execute([$station_id]);
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("  %-14s  ₱%s\n", $row['fuel_type'], $row['price_per_liter']);
}

echo "\n=== 3. Simulated 17-tank display (sample, first per fuel type) ===\n";
$TANK_CONFIG = [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1,  'capacity'=>50000],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>7,  'capacity'=>20000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>8,  'capacity'=>45000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>10, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>14, 'capacity'=>20000],
];
$FULL_CONFIG_SIZES = ['diesel'=>6, 'kerosene'=>1, 'turbo diesel'=>2, 'xcs plus'=>4, 'xtra unl'=>4];
foreach ($TANK_CONFIG as $tc) {
    $ft_key = strtolower(trim($tc['fuel_type']));
    $inv    = $fi_lookup[$ft_key] ?? null;
    $count  = $FULL_CONFIG_SIZES[$ft_key] ?? 1;
    $cur    = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $share  = $count > 0 ? round($cur / $count, 2) : 0;
    $fill   = $tc['capacity'] > 0 ? min(100, round($share / $tc['capacity'] * 100, 1)) : 0;
    $status = $share <= 0 ? 'Out of Stock' : ($fill <= 10 ? 'Critical' : ($fill <= 25 ? 'Low' : 'Available'));
    printf("  Tank %-2d  %-14s  share=%-10s  capacity=%-8s  fill=%5s%%  status=%s\n",
        $tc['tanker_num'],
        $tc['fuel_type'],
        number_format($share, 2) . ' L',
        number_format($tc['capacity'], 0) . ' L',
        $fill,
        $status
    );
}

echo "\n=== 4. user_station_name() test ===\n";
$name = $pdo->query("SELECT name FROM stations WHERE id = $station_id LIMIT 1")->fetchColumn();
echo "  Station name: $name\n";
