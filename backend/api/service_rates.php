<?php
// API endpoint to get service rates from database
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');

try {
    $pdo = $GLOBALS['pdo'];
    
    // Get fixed service fees from database
    $stmt = $pdo->query("
        SELECT service_name, base_rate_per_hour 
        FROM job_order_service_types 
        WHERE active = TRUE 
        ORDER BY sort_order
    ");
    
    $service_fees = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $service_fees[$row['service_name']] = (float)$row['base_rate_per_hour'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $service_fees,
        'type' => 'fixed_fees' // Indicate these are fixed fees, not hourly rates
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
