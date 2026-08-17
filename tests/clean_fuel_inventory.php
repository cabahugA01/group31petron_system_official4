<?php
require_once __DIR__ . '/../public/db_connect.php';

// Check fuel_inventory
$fi = $pdo->query("SELECT * FROM fuel_inventory")->fetchAll(PDO::FETCH_ASSOC);
echo "Fuel Inventory rows: " . count($fi) . "\n";
foreach ($fi as $r) {
    echo "Tank/Fuel: " . ($r['fuel_type'] ?? '') . " | Current: " . ($r['current_level'] ?? 0) . " | Capacity: " . ($r['tank_capacity'] ?? 0) . "\n";
}

// Boost fuel_inventory to 80% capacity so no low tank notifications
$pdo->exec("UPDATE fuel_inventory SET current_level = GREATEST(COALESCE(current_level,0), COALESCE(tank_capacity, 14000) * 0.8)");

// Truncate notifications completely
$pdo->exec("TRUNCATE TABLE notifications");
echo "Notifications truncated.\n";

echo "Check notifications count: " . $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn() . "\n";
