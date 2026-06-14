<?php
// Audit script — checks all module sources for completeness
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MODULE_SETTINGS (Global) ===" . PHP_EOL;
$rows = $pdo->query("SELECT module_key, module_name, is_enabled FROM module_settings ORDER BY module_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  " . str_pad($r['module_key'], 25) . " | " . ($r['is_enabled'] ? 'ON' : 'OFF') . " | " . $r['module_name'] . PHP_EOL;

echo PHP_EOL . "=== STATION_MODULES (Distinct module_keys in DB) ===" . PHP_EOL;
$rows2 = $pdo->query("SELECT DISTINCT module_key, COUNT(*) as station_count, SUM(is_enabled) as enabled_count FROM station_modules GROUP BY module_key ORDER BY module_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) echo "  " . str_pad($r['module_key'], 25) . " | stations=" . $r['station_count'] . " | enabled=" . $r['enabled_count'] . PHP_EOL;

echo PHP_EOL . "=== STATIONS COUNT ===" . PHP_EOL;
$cnt = $pdo->query("SELECT COUNT(*) FROM stations WHERE status='Active'")->fetchColumn();
echo "  Active stations: $cnt" . PHP_EOL;

echo PHP_EOL . "=== MODULES IN STATION_MODULES METADATA (station_module_api.php) ===" . PHP_EOL;
$in_api = ['transactions','fuel_management','merchandise','job_orders','payments','inventory','calendar','reports'];
foreach ($in_api as $m) echo "  $m" . PHP_EOL;

echo PHP_EOL . "=== MODULES IN MODULE_MENU_MAP (lib.php) ===" . PHP_EOL;
$in_map = ['transactions','job_orders','fuel_management','merchandise','inventory','calendar','reports','customers'];
foreach ($in_map as $m) echo "  $m" . PHP_EOL;

echo PHP_EOL . "=== MISSING ANALYSIS ===" . PHP_EOL;
$station_mod_keys = array_column($rows2, 'module_key');
$settings_keys = array_column($rows, 'module_key');
$all_needed = ['transactions','fuel_management','merchandise','job_orders','payments','inventory','calendar','reports','customers'];

echo "In needed but NOT in station_modules DB:" . PHP_EOL;
foreach ($all_needed as $m) {
    if (!in_array($m, $station_mod_keys)) echo "  *** MISSING: $m" . PHP_EOL;
}
echo "In station_modules DB but NOT in needed list:" . PHP_EOL;
foreach ($station_mod_keys as $m) {
    if (!in_array($m, $all_needed)) echo "  *** EXTRA: $m" . PHP_EOL;
}
echo PHP_EOL . "Done." . PHP_EOL;
