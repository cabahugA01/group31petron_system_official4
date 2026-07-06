<?php
require_once '../lib.php';
require_once '../../public/db_connect.php';
header('Content-Type: application/json');  try {  global $pdo;  // Get request ID  $requestId = $_GET['id'] ?? null;  if (!$requestId) {  echo json_encode(['success' => false, 'message' => 'Request ID required']);  exit;  }  // Get request details with full information  $stmt = $pdo->prepare('  SELECT sr.*,  u.name as staff_name,  ip.sku as item_sku,  ip.product_name as item_name,  ip.category as item_category,  ip.stock as current_stock  FROM stock_requests sr  LEFT JOIN users u ON sr.staff_id = u.id  LEFT JOIN inventory_products ip ON sr.item_id = ip.id  WHERE sr.id = ? AND sr.status = "Pending"  ');  $stmt->execute([$requestId]);  $request = $stmt->fetch(PDO::FETCH_ASSOC);  if (!$request) {  echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);  exit;  }  echo json_encode([  'success' => true,  'request' => $request  ]);  } catch (Exception $e) {  echo json_encode([  'success' => false,  'message' => 'Database error: ' . $e->getMessage()  ]);
}
?>
