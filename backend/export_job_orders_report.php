<?php
/**  * Job Orders Report Export Backend  * Handles advanced export with custom date ranges and filters  */  require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';  // Check if user is logged in
require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();  // Access control
if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {  http_response_code(403);  echo json_encode(['success' => false, 'message' => 'Access denied']);  exit;
}  // Get parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$export_type = $_GET['type'] ?? 'excel';
$status_filter = $_GET['status'] ?? 'all';  // Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = date('Y-m-d');  try {  // Build query  $sql = "SELECT  jo.id,  COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS job_order_id,  COALESCE(c.name, jo.customer_name, 'Walk-in') AS customer_name,  jo.service_type,  jo.vehicle_plate,  jo.status,  jo.payment_status,  jo.payment_mode,  COALESCE(jo.total_cost, 0) AS total_amount,  jo.created_at,  u.name AS staff_encoder  FROM job_orders jo  LEFT JOIN customers c ON jo.customer_id = c.id  LEFT JOIN users u ON jo.created_by = u.user_id  WHERE jo.station_id = ?  AND DATE(jo.created_at) BETWEEN ? AND ?";  $params = [$station_id, $start_date, $end_date];  if ($status_filter !== 'all') {  $sql .= " AND jo.status = ?";  $params[] = $status_filter;  }  $sql .= " ORDER BY jo.created_at DESC";  $stmt = $pdo->prepare($sql);  $stmt->execute($params);  $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);  // Export based on type  if ($export_type === 'json') {  header('Content-Type: application/json');  echo json_encode([  'success' => true,  'data' => $job_orders,  'date_range' => ['start' => $start_date, 'end' => $end_date],  'total_records' => count($job_orders)  ]);  }  } catch (Exception $e) {  http_response_code(500);  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
