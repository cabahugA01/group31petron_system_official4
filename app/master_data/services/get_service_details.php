<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/lib.php';

session_start();
require_login();

$user_id = $_GET['user_id'] ?? 0;
$station_id = $_SESSION['user']['station_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT se.*, 
               COUNT(si.id) as items_count,
               GROUP_CONCAT(CONCAT(si.description, ' (₱', si.total_cost, ')') SEPARATOR '<br>') as items_summary
        FROM service_entries se
        LEFT JOIN service_items si ON se.id = si.service_id
        WHERE se.station_id = ? 
          AND se.user_id = ? 
          AND se.status IN ('Pending', 'In Progress', 'Completed')
          AND se.status != 'Finalized'
        GROUP BY se.id
        ORDER BY se.created_at DESC
    ");
    $stmt->execute([$station_id, $user_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($services);
} catch (Exception $e) {
    echo json_encode([]);
}
?>