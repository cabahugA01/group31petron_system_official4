<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$role = role_key($me['role'] ?? '');

// Restrict to managers only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$fuel_id = $_GET['id'] ?? 0;
$station_id = user_station_id();

try {
    $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id = ? AND (station_id = ? OR station_id IS NULL)");
    $stmt->execute([$fuel_id, $station_id]);
    $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fuel) {
        echo json_encode([
            'success' => true,
            'fuel' => [
                'id' => $fuel['id'],
                'fuel_type' => $fuel['fuel_type'],
                'price_per_liter' => $fuel['price_per_liter'],
                'current_level' => $fuel['current_level'],
                'capacity' => $fuel['capacity'],
                'status' => $fuel['status'],
                'last_updated' => $fuel['last_updated']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fuel inventory not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
