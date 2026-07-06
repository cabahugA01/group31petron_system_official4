<?php
// Check stock for Pilay and Diesel
error_reporting(0);
ini_set('display_errors', 0);  require_once __DIR__ . '/../public/db_connect.php';  header('Content-Type: application/json; charset=utf-8');  $action = $_GET['action'] ?? '';  if ($action === 'check_stock') {  try {  // Check station inventory for Pilay and Diesel  $stmt = $pdo->prepare("  SELECT si.station_id, p.name, si.stock_level, si.cost, si.price, si.unit,  ft.name as fuel_type  FROM station_inventory si  JOIN products p ON si.product_id = p.id  LEFT JOIN fuel_types ft ON LOWER(p.name) = LOWER(ft.name)  WHERE (LOWER(p.name) LIKE LOWER('%pilay%') OR LOWER(p.name) LIKE LOWER('%diesel%'))  AND si.station_id = ?  ORDER BY p.name  ");  $stmt->execute([1]); // Station 1  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo json_encode([  'success' => true,  'items' => $items,  'pilay_count' => count(array_filter($items, function($item) {  return stripos(strtolower($item['name']), 'pilay') !== false;  })),  'diesel_count' => count(array_filter($items, function($item) {  return stripos(strtolower($item['name']), 'diesel') !== false;  }))  ]);  } catch (Exception $e) {  echo json_encode(['success' => false, 'error' => $e->getMessage()]);  }
} else {  echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
