<?php
/**
 * Process Job Order Only Transaction
 * 
 * Handles creation of job order-only transactions (no merchandise).
 * Sets transaction_type='job_order'.
 */

session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

try {
    $me = current_user();
    $station_id = user_station_id();
    $role = role_key($me['role'] ?? '');
    
    // Verify staff permission
    if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        throw new Exception('Unauthorized access');
    }
    
    // Validate required fields
    $required_fields = ['customer_name', 'service_type', 'service_fee', 'payment_method'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $engine_number  = strtoupper(trim($_POST['engine_number'] ?? ''));
    $chassis_number = strtoupper(trim($_POST['chassis_number'] ?? ''));
    
    if (empty($engine_number)) {
        throw new Exception("Engine Number is required for vehicle identification.");
    }
    if (empty($chassis_number)) {
        throw new Exception("Chassis Number (VIN) is required for vehicle security.");
    }

    // Extract form data
    $customer_name = trim($_POST['customer_name']);
    $contact_number = trim($_POST['contact_number'] ?? '');
    $vehicle_plate = trim($_POST['vehicle_plate'] ?? '');
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $service_type = trim($_POST['service_type']);
    $assigned_mechanic = trim($_POST['assigned_mechanic'] ?? '');
    $service_fee = floatval($_POST['service_fee']);
    $payment_method = trim($_POST['payment_method']);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    
    // Calculate balance
    $balance_due = max(0, $service_fee - $amount_paid);
    $payment_status = ($balance_due <= 0.01) ? 'Paid' : (($amount_paid > 0) ? 'Partial' : 'Pending Payment');
    
    // Get current shift
    $shift_period = '';
    $shift_name = '';
    try {
        $shift_stmt = $pdo->prepare("
            SELECT shift_period, shift_name 
            FROM labor_sessions 
            WHERE user_id = ? AND end_time IS NULL 
            ORDER BY start_time DESC LIMIT 1
        ");
        $shift_stmt->execute([$me['id']]);
        $shift_row = $shift_stmt->fetch(PDO::FETCH_ASSOC);
        if ($shift_row) {
            $shift_period = $shift_row['shift_period'] ?? '';
            $shift_name = $shift_row['shift_name'] ?? '';
        }
    } catch (Exception $e) {}
    
    // Generate job order number
    $job_order_number = 'JO-' . $station_id . '-' . date('Ymd-His') . '-' . mt_rand(100, 999);
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Insert job order
    $stmt = $pdo->prepare("
        INSERT INTO job_orders (
            job_order_number,
            customer_name,
            contact_number,
            vehicle_plate,
            vehicle_type,
            engine_number,
            chassis_number,
            service_type,
            assigned_mechanic,
            service_fee,
            amount_paid,
            balance_due,
            payment_status,
            payment_method,
            remarks,
            transaction_type,
            status,
            staff_id,
            station_id,
            shift_period,
            shift_name,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            'job_order', 
            'Pending', 
            ?, ?, ?, ?, NOW()
        )
    ");
    
    $stmt->execute([
        $job_order_number,
        $customer_name,
        $contact_number,
        $vehicle_plate,
        $vehicle_type,
        $engine_number,
        $chassis_number,
        $service_type,
        $assigned_mechanic,
        $service_fee,
        $amount_paid,
        $balance_due,

        $payment_status,
        $payment_method,
        $remarks,
        $me['id'],
        $station_id,
        $shift_period,
        $shift_name
    ]);
    
    $job_order_id = $pdo->lastInsertId();
    
    // Commit transaction
        $pdo->commit();

    notify_transaction_submission($pdo, (int)$station_id, [
        'transaction_id'    => $job_order_number,
        'transaction_db_id' => (int)$job_order_id,
        'transaction_type'  => 'job_order',
        'total_amount'      => (float)$service_fee,
        'customer_name'     => $customer_name,
        'staff_name'        => $me['name'] ?? $me['username'] ?? 'Staff',
        'shift_period'      => $shift_period ?? $shift_name ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Job Order created successfully',
        'job_order_id' => $job_order_id,
        'job_order_number' => $job_order_number,
        'payment_status' => $payment_status,
        'balance_due' => $balance_due
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
