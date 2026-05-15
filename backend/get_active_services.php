<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = $_GET['station_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT se.*, 
               COUNT(si.id) as item_count,
               COALESCE(SUM(si.total_cost), se.grand_total) as total_cost
        FROM service_entries se
        LEFT JOIN service_items si ON se.id = si.service_entry_id
        WHERE se.station_id = ? 
        AND se.user_id = ?
        AND se.status IN ('In Progress', 'Pending Parts')
        GROUP BY se.id
        ORDER BY se.created_at DESC
    ");
    
    $stmt->execute([$station_id, $user_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'services' => $services
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>