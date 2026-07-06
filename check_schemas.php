<?php
require_once 'public/db_connect.php';
$tables = ['vehicles', 'vehicle_types', 'inventory_products', 'products', 'services', 'service_types'];
foreach($tables as $t) {  try {  $q = $pdo->query("DESCRIBE $t");  echo "=== $t ===\n";  while($r = $q->fetch(PDO::FETCH_ASSOC)) {  echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";  }  } catch(Exception $e) {  echo "$t: not found or error (" . $e->getMessage() . ")\n";  }
}
