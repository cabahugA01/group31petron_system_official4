<?php
/**
 * Save Unified Transaction (Merchandise/Service/Combined)
 * Determines transaction type based on form data and saves accordingly
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // ========== FORM DATA EXTRACTION ==========
    
    // Customer info
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_contact = trim($_POST['customer_contact'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    
    // Service info (Job Order fields)
    $service_type = trim($_POST['service_type'] ?? '');
    $vehicle_plate = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $vehicle_brand = trim($_POST['vehicle_brand'] ?? '');
    $vehicle_model = trim($_POST['vehicle_model'] ?? '');
    $service_category = trim($_POST['service_category'] ?? '');
    $mechanic_name = trim($_POST['mechanic_name'] ?? '');
    $service_notes = trim($_POST['service_notes'] ?? '');
    $service_fee = (float)($_POST['service_fee'] ?? 0);
    
    // Merchandise items (from cart)
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    if (!is_array($cart_items)) {
        $cart_items = [];
    }
    
    // Shift info
    $shift_period = trim($_POST['shift_period'] ?? '');
    $shift_name = trim($_POST['shift_name'] ?? '');
    
    if (empty($shift_period) || empty($shift_name)) {
        try {
            $stmt = $pdo->prepare("
                SELECT shift_period, shift_name 
                FROM labor_sessions 
                WHERE user_id = ? AND end_time IS NULL 
                ORDER BY start_time DESC 
                LIMIT 1
            ");
            $stmt->execute([$me['id']]);
            $active_shift = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($active_shift) {
                $shift_period = $active_shift['shift_period'];
                $shift_name = $active_shift['shift_name'];
            } else {
                $user_assigned_shift = strtolower(trim((string)($me['assigned_shift'] ?? '')));
                if (strpos($user_assigned_shift, 'shift 1') !== false || strpos($user_assigned_shift, '1') !== false || $user_assigned_shift === 'first') {
                    $shift_period = 'first';
                    $shift_name = 'First Shift: 6:00 AM – 2:00 PM';
                } elseif (strpos($user_assigned_shift, 'shift 2') !== false || strpos($user_assigned_shift, '2') !== false || $user_assigned_shift === 'second') {
                    $shift_period = 'second';
                    $shift_name = 'Second Shift: 2:00 PM – 12:00 Midnight';
                } else {
                    $current_time = date('H:i:s');
                    $stmt = $pdo->prepare("
                        SELECT shift_key, shift_name 
                        FROM shift_periods 
                        WHERE is_active = 1 
                          AND start_time <= ? 
                          AND end_time >= ? 
                        ORDER BY sort_order ASC 
                        LIMIT 1
                    ");
                    $stmt->execute([$current_time, $current_time]);
                    $detected_shift = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($detected_shift) {
                        $shift_period = $detected_shift['shift_key'];
                        $shift_name = $detected_shift['shift_name'];
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore
        }
    }
    
    if (empty($shift_period)) {
        $shift_period = 'general';
        $shift_name = 'General';
    }
    
    // ========== DETERMINE TRANSACTION TYPE ==========
    
    $has_service = !empty($service_type) && $service_fee > 0;
    $has_merchandise = count($cart_items) > 0;
    
    if (!$has_service && !$has_merchandise) {
        throw new Exception('Transaction must have either a service or merchandise items');
    }
    
    if ($has_service && $has_merchandise) {
        $transaction_type = 'combined';
    } elseif ($has_service) {
        $transaction_type = 'job_order';
    } else {
        $transaction_type = 'merchandise';
    }
    
    // ========== CALCULATE TOTALS ==========
    
    $merchandise_total = 0;
    foreach ($cart_items as $item) {
        $qty = (int)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $merchandise_total += $qty * $price;
    }
    
    $grand_total = $service_fee + $merchandise_total;
    
    if ($grand_total <= 0) {
        throw new Exception('Transaction total must be greater than zero');
    }
    
    // ========== GENERATE TRANSACTION ID ==========
    
    $transaction_id = 'TXN-' . $station_id . '-' . date('YmdHis') . '-' . rand(1000, 9999);
    
    // ========== INSERT MERCHANDISE_TRANSACTIONS RECORD ==========
    
    $stmt = $pdo->prepare("
        INSERT INTO merchandise_transactions (
            transaction_id,
            station_id,
            staff_id,
            customer_name,
            customer_contact,
            transaction_type,
            job_order_service,
            job_order_vehicle_plate,
            job_order_vehicle_type,
            job_order_vehicle_brand,
            job_order_vehicle_model,
            job_order_service_category,
            job_order_mechanic_name,
            remarks,
            total_amount,
            payment_method,
            payment_status,
            validation_status,
            workflow_status,
            shift_period,
            shift_name,
            transaction_date,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW()
        )
    ");
    
    $payment_status = ($payment_method === 'Credit') ? 'Pending Payment' : 'Paid';
    $validation_status = 'Pending';
    $workflow_status = ($transaction_type === 'job_order' || $transaction_type === 'combined') ? 'Pending' : 'Completed';
    
    $stmt->execute([
        $transaction_id,
        $station_id,
        $me['id'],
        $customer_name,
        $customer_contact,
        $transaction_type,
        $service_type,
        $vehicle_plate,
        $vehicle_type,
        $vehicle_brand,
        $vehicle_model,
        $service_category,
        $mechanic_name,
        $service_notes,
        $grand_total,
        $payment_method,
        $payment_status,
        $validation_status,
        $workflow_status,
        $shift_period,
        $shift_name
    ]);
    
    $transaction_db_id = $pdo->lastInsertId();
    
    // ========== INSERT TRANSACTION ITEMS ==========
    
    // 1. Add service as an item (if exists)
    if ($has_service) {
        $stmt_item = $pdo->prepare("
            INSERT INTO merchandise_transaction_items (
                transaction_id,
                product_id,
                product_name,
                sku,
                category,
                quantity,
                unit_price,
                item_type,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'service', NOW())
        ");
        
        $stmt_item->execute([
            $transaction_db_id,
            0, // No product_id for services
            $service_type,
            'SERVICE',
            'Service',
            1,
            $service_fee
        ]);
    }
    
    // 2. Add merchandise items
    if ($has_merchandise) {
        $stmt_item = $pdo->prepare("
            INSERT INTO merchandise_transaction_items (
                transaction_id,
                product_id,
                product_name,
                sku,
                category,
                quantity,
                unit_price,
                item_type,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'merchandise', NOW())
        ");
        
        foreach ($cart_items as $item) {
            $stmt_item->execute([
                $transaction_db_id,
                (int)($item['product_id'] ?? 0),
                trim($item['product_name'] ?? ''),
                trim($item['sku'] ?? ''),
                trim($item['category'] ?? 'General'),
                (int)($item['quantity'] ?? 0),
                (float)($item['unit_price'] ?? 0)
            ]);
        }
    }
    
    // ========== COMMIT TRANSACTION ==========
    
        $pdo->commit();

    // ── Single notification to manager for any transaction type ──
    notify_transaction_submission($pdo, (int)$station_id, [
        'transaction_id'    => $transaction_id,
        'transaction_db_id' => (int)$transaction_db_id,
        'transaction_type'  => $transaction_type,
        'total_amount'      => (float)$grand_total,
        'customer_name'     => $customer_name,
        'staff_name'        => $me['name'] ?? $me['username'] ?? 'Staff',
        'shift_period'      => $shift_period ?? $shift_name ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'transaction_id' => $transaction_id,
        'transaction_db_id' => $transaction_db_id,
        'transaction_type' => $transaction_type,
        'total_amount' => $grand_total,
        'message' => ucfirst($transaction_type) . ' transaction saved successfully'
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Save unified transaction error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
