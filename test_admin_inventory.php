<?php
session_start();
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';  $me = current_user();
$station_id = (int)user_station_id();  echo "Current User: " . ($me['username'] ?? 'unknown') . "\n";
echo "Station ID: $station_id\n\n";  // Test the exact query from the page
try {  $stmt = $pdo->prepare("  SELECT ip.id,  ip.product_name AS name,  ip.category  AS category_name,  ip.status,  COALESCE(si.stock_level, ip.stock, 0)  AS stock_level  FROM inventory_products ip  LEFT JOIN station_inventory si  ON si.product_id = ip.id AND si.station_id = ?  WHERE LOWER(COALESCE(ip.category, '')) <> 'fuel'  AND LOWER(COALESCE(ip.status, 'active')) <> 'inactive'  ORDER BY ip.category, ip.product_name  LIMIT 10  ");  $stmt->execute([$station_id]);  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "Query returned " . count($items) . " items (showing first 10)\n\n";  foreach ($items as $item) {  echo "- {$item['name']} | Category: {$item['category_name']} | Status: {$item['status']} | Stock: {$item['stock_level']}\n";  }  } catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}
