<?php
/**
 * Get Unified Transaction History
 * Shows ALL transaction types: Job Order, Merchandise, Combined
 */
session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

try {
    $me = current_user();
    $station_id = user_station_id();
    
    // Build filters
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    
    // Fetch job orders
    $jo_stmt = $pdo->prepare("
        SELECT 
            'Job Order' AS type,
            job_order_number AS reference,
            customer_name,
            service_fee AS amount,
            payment_method,
            payment_status,
            created_at AS date
        FROM job_orders
        WHERE station_id = ? 
        AND staff_id = ?
        AND DATE(created_at) BETWEEN ? AND ?
    ");
    $jo_stmt->execute([$station_id, $me['id'], $date_from, $date_to]);
    $job_orders = $jo_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch merchandise transactions
    $mt_stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN transaction_type = 'combined' THEN 'Combined'
                ELSE 'Merchandise'
            END AS type,
            transaction_id AS reference,
            customer_name,
            total_amount AS amount,
            payment_method,
            payment_status,
            transaction_date AS date
        FROM merchandise_transactions
        WHERE station_id = ? 
        AND staff_id = ?
        AND DATE(transaction_date) BETWEEN ? AND ?
    ");
    $mt_stmt->execute([$station_id, $me['id'], $date_from, $date_to]);
    $merch_txns = $mt_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge and sort
    $all_transactions = array_merge($job_orders, $merch_txns);
    usort($all_transactions, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    echo json_encode([
        'success' => true,
        'data' => $all_transactions,
        'total' => count($all_transactions)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
