<?php
/**
 * Get Job Order Tracker Data
 * Shows Job Order Only + Combined transactions
 */
session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

try {
    $me = current_user();
    $station_id = user_station_id();
    
    // Build WHERE clause
    $where = "WHERE jo.station_id = ? AND jo.transaction_type IN ('job_order', 'combined')";
    $params = [$station_id];
    
    // Filters
    if (!empty($_GET['date_from'])) {
        $where .= " AND DATE(jo.created_at) >= ?";
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where .= " AND DATE(jo.created_at) <= ?";
        $params[] = $_GET['date_to'];
    }
    if (!empty($_GET['status'])) {
        $where .= " AND jo.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['mechanic'])) {
        $where .= " AND jo.assigned_mechanic LIKE ?";
        $params[] = '%' . $_GET['mechanic'] . '%';
    }
    if (!empty($_GET['service_type'])) {
        $where .= " AND jo.service_type LIKE ?";
        $params[] = '%' . $_GET['service_type'] . '%';
    }
    
    // Count total
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders jo $where");
    $count_stmt->execute($params);
    $total = $count_stmt->fetchColumn();
    
    // Pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    // Fetch data
    $stmt = $pdo->prepare("
        SELECT 
            jo.id,
            jo.job_order_number,
            jo.customer_name,
            jo.vehicle_plate,
            jo.vehicle_type,
            jo.service_type,
            jo.assigned_mechanic,
            jo.service_fee,
            jo.status,
            jo.payment_status,
            jo.created_at,
            jo.transaction_type
        FROM job_orders jo
        $where
        ORDER BY jo.created_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $records,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
