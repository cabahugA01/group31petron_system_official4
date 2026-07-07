<?php
require_once __DIR__ . '/../../../backend/lib.php';
require_once __DIR__ . '/../../../public/db_connect.php';
require_login();  header('Content-Type: application/json');  $service_id = $_GET['id'] ?? '';
if (!$service_id) {  http_response_code(400);  echo json_encode(['error' => 'Service ID required']);  exit;
}  try {  $stmt = $pdo->prepare("SELECT sr.*, sc.name AS category_name FROM service_rates sr LEFT JOIN service_categories sc ON sr.service_category_id = sc.id WHERE sr.id = ?");  $stmt->execute([$service_id]);  $service = $stmt->fetch(PDO::FETCH_ASSOC);  if (!$service) {  http_response_code(404);  echo json_encode(['error' => 'Service not found']);  exit;  }  // Normalize fields expected by the UI  $normalized = [  'id' => $service['id'],  'service_name' => $service['rate_name'] ?? $service['service_name'] ?? '',  'category' => $service['category_name'] ?? $service['category'] ?? '',  'rate' => isset($service['flat_rate']) ? (float)$service['flat_rate'] : (isset($service['rate']) ? (float)$service['rate'] : 0),  'status' => (isset($service['is_active']) && $service['is_active']) ? 'active' : (isset($service['status']) ? $service['status'] : 'inactive'),  'updated_at' => $service['updated_at'] ?? null  ];  echo json_encode($normalized);
} catch (Exception $e) {  http_response_code(500);  echo json_encode(['error' => 'Database error']);
}
?>
