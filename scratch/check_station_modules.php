<?php
require_once __DIR__ . '/../public/db_connect.php';

// Check stations
$stmt = $pdo->query("SELECT id,name,region,location FROM stations WHERE status='Active' LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Stations count OK: " . count($rows) . PHP_EOL;
foreach ($rows as $r) echo "  -> " . $r['id'] . " | " . $r['name'] . " | " . ($r['region'] ?? '') . PHP_EOL;

// Check station_modules
$stmt2 = $pdo->query("SELECT station_id, module_key, is_enabled FROM station_modules LIMIT 16");
$mods = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "Station modules (" . count($mods) . " rows):" . PHP_EOL;
foreach ($mods as $m) echo "  station=" . $m['station_id'] . " module=" . $m['module_key'] . " enabled=" . $m['is_enabled'] . PHP_EOL;

// Check the API auth guard — does superadmin check match?
echo PHP_EOL . "Done." . PHP_EOL;
