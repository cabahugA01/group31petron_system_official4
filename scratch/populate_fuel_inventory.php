<?php
require __DIR__ . '/../public/db_connect.php';

$fuels = [
    ['id' => 10, 'name' => 'Diesel', 'price' => 64.35],
    ['id' => 11, 'name' => 'Turbo Diesel', 'price' => 68.10],
    ['id' => 12, 'name' => 'XCS Plus', 'price' => 71.25],
    ['id' => 13, 'name' => 'XTRA UNL', 'price' => 68.50],
    ['id' => 14, 'name' => 'Kerosene', 'price' => 58.90]
];

$station_id = 1253;

foreach ($fuels as $f) {
    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND fuel_type_id = ?");
    $stmt->execute([$station_id, $f['id']]);
    if (!$stmt->fetchColumn()) {
        $stmt_ins = $pdo->prepare("
            INSERT INTO fuel_inventory 
            (station_id, fuel_type_id, fuel_type, current_stock, current_level, capacity, price_per_liter, status, last_updated)
            VALUES (?, ?, ?, 10000.00, 10000.00, 20000.00, ?, 'Normal', NOW())
        ");
        $stmt_ins->execute([$station_id, $f['id'], $f['name'], $f['price']]);
        echo "Inserted fuel_inventory for {$f['name']}\n";
    } else {
        echo "Already exists: {$f['name']}\n";
    }
}
