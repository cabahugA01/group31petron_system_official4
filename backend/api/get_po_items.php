<?php
// Get PO Items API endpoint
require_once '../config/database_config.php';
require_once '../public/db_connect.php';  header('Content-Type: application/json');  $po_id = $_GET['po_id'] ?? 0;
if (!$po_id) {  echo json_encode(['error' => 'PO ID required']);  exit;
}  try {  $stmt = $pdo->prepare("  SELECT  pi.id,  pi.product_id,  pi.quantity_ordered,  pi.unit_price,  pi.quantity_received,  ip.product_name,  ip.category,  (pi.quantity_ordered - COALESCE(pi.quantity_received, 0)) as remaining_quantity  FROM po_items pi  LEFT JOIN inventory_products ip ON pi.product_id = ip.id  WHERE pi.po_id = ?  ORDER BY ip.product_name  ");  $stmt->execute([$po_id]);  $items = $stmt->fetchAll();  echo json_encode(['items' => $items]);  } catch (Exception $e) {  echo json_encode(['error' => $e->getMessage()]);
}
?>
