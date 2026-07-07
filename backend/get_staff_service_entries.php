<?php
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: application/json');

$station_id = $_GET['station_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT se.* 
        FROM service_entries se
        WHERE se.station_id = ? 
        AND se.user_id = ?
        ORDER BY se.updated_at DESC, se.created_at DESC
    ");
    
    $stmt->execute([$station_id, $user_id]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'entries' => $entries
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>