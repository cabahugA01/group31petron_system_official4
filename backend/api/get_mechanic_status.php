<?php
/**
 * GET /backend/api/get_mechanic_status.php?mechanic_id=X
 * Returns whether a mechanic has ongoing (Pending / In Progress) job orders.
 */
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$mechanic_id = (int)($_GET['mechanic_id'] ?? 0);
if ($mechanic_id <= 0) {
    echo json_encode(['busy' => false]);
    exit;
}

try {
    // Check job_orders table
    $stmt = $pdo->prepare("
        SELECT jo.id,
               COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
               jo.status,
               COALESCE(jo.service_type, jo.service_description, 'Service') AS service_label,
               COALESCE(jo.vehicle_plate, '') AS vehicle_plate,
               jo.created_at
        FROM job_orders jo
        WHERE jo.assigned_mechanic_id = ?
          AND jo.status IN ('Pending', 'Pending Validation', 'In Progress', 'Approved', 'Validated')
        ORDER BY FIELD(jo.status, 'In Progress', 'Approved', 'Validated', 'Pending', 'Pending Validation'),
                 jo.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$mechanic_id]);
    $active_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also check merchandise_transactions with job_order/combined type
    $stmt2 = $pdo->prepare("
        SELECT mt.id,
               COALESCE(mt.transaction_id, CONCAT('MT-', mt.id)) AS jo_ref,
               COALESCE(mt.workflow_status, mt.validation_status, 'Pending') AS status,
               COALESCE(mt.job_order_service, 'Service') AS service_label,
               COALESCE(mt.job_order_vehicle_plate, '') AS vehicle_plate,
               mt.created_at
        FROM merchandise_transactions mt
        WHERE mt.job_order_mechanic_id = ?
          AND COALESCE(mt.workflow_status, '') NOT IN ('Completed', 'Rejected', 'Cancelled')
          AND mt.transaction_type IN ('job_order', 'combined')
        ORDER BY mt.created_at DESC
        LIMIT 5
    ");
    $stmt2->execute([$mechanic_id]);
    $mt_jobs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $all_jobs = array_merge($active_jobs, $mt_jobs);

    if (empty($all_jobs)) {
        echo json_encode(['busy' => false]);
    } else {
        echo json_encode([
            'busy'       => true,
            'job_count'  => count($all_jobs),
            'jobs'       => array_map(fn($j) => [
                'jo_ref'        => $j['jo_ref'],
                'status'        => $j['status'],
                'service_label' => $j['service_label'],
                'vehicle_plate' => $j['vehicle_plate'],
                'created_at'    => $j['created_at'],
            ], $all_jobs),
        ]);
    }
} catch (Exception $e) {
    // Fail open — don't block the workflow on a DB error
    echo json_encode(['busy' => false, 'error' => $e->getMessage()]);
}
