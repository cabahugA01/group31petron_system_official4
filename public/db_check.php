<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: text/plain');

echo "=== pending_price_approvals columns ===\n";
try {  $cols = $pdo->query('SHOW COLUMNS FROM pending_price_approvals')->fetchAll(PDO::FETCH_ASSOC);  foreach($cols as $c) echo $c['Field'] . ' (' . $c['Type'] . ")\n";
} catch(Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

echo "\n=== fuel_inventory station_id summary ===\n";
try {  $rows = $pdo->query('SELECT station_id, COUNT(*) as cnt FROM fuel_inventory GROUP BY station_id')->fetchAll(PDO::FETCH_ASSOC);  foreach($rows as $r) echo 'station_id=' . $r['station_id'] . ' => ' . $r['cnt'] . " records\n";  if(empty($rows)) echo "(empty table)\n";
} catch(Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

echo "\n=== inventory_products station_id summary (non-Fuel) ===\n";
try {  $rows = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM inventory_products WHERE category != 'Fuel' GROUP BY station_id")->fetchAll(PDO::FETCH_ASSOC);  foreach($rows as $r) echo 'station_id=' . $r['station_id'] . ' => ' . $r['cnt'] . " records\n";  if(empty($rows)) echo "(empty)\n";
} catch(Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

echo "\n=== stations table ===\n";
try {  $rows = $pdo->query('SELECT id, name FROM stations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);  foreach($rows as $r) echo 'id=' . $r['id'] . ' => ' . $r['name'] . "\n";
} catch(Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

echo "\n=== manager/admin users with station_id ===\n";
try {  $rows = $pdo->query("SELECT id, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) as name, role, station_id FROM users WHERE role IN ('manager','admin','superadmin') LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);  foreach($rows as $r) echo 'id=' . $r['id'] . ' | ' . trim($r['name']) . ' | role=' . $r['role'] . ' | station_id=' . $r['station_id'] . "\n";
} catch(Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }

echo "\n=== job_order_service_types count ===\n";
try {  $cnt = $pdo->query('SELECT COUNT(*) FROM job_order_service_types')->fetchColumn();  echo 'Total service types: ' . $cnt . "\n";
} catch(Exception $e) { echo 'Table missing or error: ' . $e->getMessage() . "\n"; }

echo "\n=== TEST fuel query for each station_id ===\n";
try {  $sids = $pdo->query('SELECT DISTINCT station_id FROM fuel_inventory')->fetchAll(PDO::FETCH_COLUMN);  foreach($sids as $sid) {  $cnt = $pdo->prepare('SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ?');  $cnt->execute([$sid]);  echo 'station_id=' . $sid . ' => ' . $cnt->fetchColumn() . " fuel records\n";  }
} catch(Exception $e) { echo 'ERROR: ' . $e->getMessage() . "\n"; }
