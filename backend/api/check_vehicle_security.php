<?php
/**
 * Vehicle Identification & Security Check API
 * Checks:
 * 1. Active Job Order Plate Number Uniqueness
 * 2. Required Engine Number & Chassis Number (VIN)
 * 3. Duplicate Engine Number or Chassis Number under a different vehicle
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../lib.php';

try {
    $me = current_user();
    if (!$me) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $station_id = (int)($me['station_id'] ?? user_station_id() ?? 0);

    $plate   = strtoupper(trim($_REQUEST['plate_number'] ?? ''));
    $engine  = strtoupper(trim($_REQUEST['engine_number'] ?? ''));
    $chassis = strtoupper(trim($_REQUEST['chassis_number'] ?? ''));

    $response = [
        'success'         => true,
        'has_active_jo'   => false,
        'active_jo_no'    => '',
        'engine_warning'  => null,
        'chassis_warning' => null,
        'warnings'        => []
    ];

    // 1. Check Active Job Order for Plate Number
    if ($plate !== '') {
        $jo_stmt = $pdo->prepare("
            SELECT job_order_number 
            FROM job_orders 
            WHERE UPPER(TRIM(vehicle_plate)) = ? 
              AND LOWER(COALESCE(status,'pending')) IN ('pending', 'in progress', 'in-progress', 'assigned')
              AND (station_id = ? OR station_id = 0 OR station_id IS NULL)
            ORDER BY id DESC LIMIT 1
        ");
        $jo_stmt->execute([$plate, $station_id]);
        $active_jo = $jo_stmt->fetchColumn();
        if ($active_jo) {
            $response['has_active_jo'] = true;
            $response['active_jo_no']  = $active_jo;
            $response['warnings'][]    = "Plate Number {$plate} currently has an active Job Order ({$active_jo}). Plate Number must be unique for active job orders.";
        }
    }

    // 2. Check Engine Number Duplicate under a Different Vehicle
    if ($engine !== '') {
        $eng_stmt = $pdo->prepare("
            SELECT vehicle_plate, customer_name 
            FROM job_orders 
            WHERE UPPER(TRIM(engine_number)) = ? 
              AND UPPER(TRIM(vehicle_plate)) != ?
              AND engine_number IS NOT NULL AND engine_number != ''
            LIMIT 1
        ");
        $eng_stmt->execute([$engine, $plate]);
        $match = $eng_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $cust_eng_stmt = $pdo->prepare("
                SELECT vehicle_plate, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS customer_name 
                FROM customers 
                WHERE UPPER(TRIM(engine_number)) = ? 
                  AND UPPER(TRIM(vehicle_plate)) != ?
                  AND engine_number IS NOT NULL AND engine_number != ''
                LIMIT 1
            ");
            $cust_eng_stmt->execute([$engine, $plate]);
            $match = $cust_eng_stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($match) {
            $other_plate = strtoupper(trim($match['vehicle_plate'] ?? 'Unknown'));
            $other_owner = trim($match['customer_name'] ?? 'Registered Owner');
            $msg = "SECURITY WARNING: Engine Number [{$engine}] is already registered under a different vehicle (Plate: {$other_plate}, Owner: {$other_owner}). Please verify vehicle identity.";
            $response['engine_warning'] = $msg;
            $response['warnings'][]     = $msg;
        }
    }

    // 3. Check Chassis Number (VIN) Duplicate under a Different Vehicle
    if ($chassis !== '') {
        $chas_stmt = $pdo->prepare("
            SELECT vehicle_plate, customer_name 
            FROM job_orders 
            WHERE UPPER(TRIM(chassis_number)) = ? 
              AND UPPER(TRIM(vehicle_plate)) != ?
              AND chassis_number IS NOT NULL AND chassis_number != ''
            LIMIT 1
        ");
        $chas_stmt->execute([$chassis, $plate]);
        $match = $chas_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            $cust_chas_stmt = $pdo->prepare("
                SELECT vehicle_plate, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS customer_name 
                FROM customers 
                WHERE UPPER(TRIM(chassis_number)) = ? 
                  AND UPPER(TRIM(vehicle_plate)) != ?
                  AND chassis_number IS NOT NULL AND chassis_number != ''
                LIMIT 1
            ");
            $cust_chas_stmt->execute([$chassis, $plate]);
            $match = $cust_chas_stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($match) {
            $other_plate = strtoupper(trim($match['vehicle_plate'] ?? 'Unknown'));
            $other_owner = trim($match['customer_name'] ?? 'Registered Owner');
            $msg = "SECURITY WARNING: Chassis Number (VIN) [{$chassis}] is already registered under a different vehicle (Plate: {$other_plate}, Owner: {$other_owner}). Please verify vehicle identity.";
            $response['chassis_warning'] = $msg;
            $response['warnings'][]      = $msg;
        }
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
