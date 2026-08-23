<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$calibration_id = $_GET['id'] ?? '';
if (!$calibration_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Calibration ID required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM fuel_calibration WHERE id = ?");
    $stmt->execute([$calibration_id]);
    $calibration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$calibration) {
        http_response_code(404);
        echo json_encode(['error' => 'Calibration not found']);
        exit;
    }
    
    echo json_encode($calibration);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
