<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();  header('Content-Type: application/json');  $notification_id = $_GET['id'] ?? '';
if (!$notification_id) {  http_response_code(400);  echo json_encode(['error' => 'Notification ID required']);  exit;
}  try {  $stmt = $pdo->prepare("SELECT n.* FROM notifications n  INNER JOIN users u ON n.user_id = u.id  WHERE n.id = ? AND n.user_id = ? AND u.role = ?");  $stmt->execute([$notification_id, current_user()['id'], current_user()['role']]);  $notification = $stmt->fetch(PDO::FETCH_ASSOC);  if (!$notification) {  http_response_code(404);  echo json_encode(['error' => 'Notification not found']);  exit;  }  echo json_encode($notification);
} catch (Exception $e) {  http_response_code(500);  echo json_encode(['error' => 'Database error']);
}
?>
