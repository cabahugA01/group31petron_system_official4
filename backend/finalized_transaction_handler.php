<?php
/**
 * Finalized Transaction Handler
 * Complete End-to-End System Flow
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? 'save_transaction';

try {
    $pdo->beginTransaction();
    
    if ($action === 'save_transaction') {
        // ── STEP 1: VALIDATE DATA ────────────────────────────────────
        $customer_name = trim($input['customer_name'] ?? '');
        $contact_number = trim($input['contact_number'] ?? '');
        $address = trim($input['address'] ?? '');
        $plate_number = trim($input['plate_number'] ?? '');
        $vehicle_type = trim($input['vehicle_type'] ?? '');
        $vehicle_brand = trim($input['vehicle_brand'] ?? '');
        $vehicle_model = trim($input['vehicle_model'] ?? '');
        $transaction_type = $input['transaction_type'] ?? 'merchandise';
        
        if (empty($customer_name)) {
            throw new Exception('Customer name is required');
        }
        
        // ── STEP 2: GET ACTIVE SHIFT ─────────────────────────────────
        $shift_stmt = $pdo->prepare("
            SELECT id, shift_period, shift_name 
            FROM labor_sessions 
            WHERE user_id = ? AND end_time IS NULL 
            ORDER BY start_time DESC LIMIT 1
        ");
        $shift_stmt->execute([$me['id']]);
        $shift = $shift_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shift) {
            throw new Exception('No active shift. Please clock in first.');
        }
        
        // ── STEP 3: GENERATE TRANSACTION ID ──────────────────────────
        $txn_id = 'TXN' . date('Ymd') . $station_id . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $reference_no = 'REF' . date('YmdHis') . $station_id;
        $timestamp = date('Y-m-d H:i:s');
        
        // ── STEP 4: PROCESS JOB ORDER (if applicable) ────────────────
        $job_order_id = null;
        $jo_number = null;
        $labor_cost = 0.00;
        
        if (in_array($transaction_type, ['job_order', 'combined'])) {
            $jo_number = 'JO' . date('Ymd') . $station_id . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $service_category = trim($input['service_category'] ?? '');
            $service_description = trim($input['service_description'] ?? '');
            $assigned_technician = (int)($input['assigned_technician'] ?? 0);
            $labor_cost = (float)($input['labor_cost'] ?? 0);
            
            if (empty($service_category)) {
                throw new Exception('Service category is required for Job Order');
            }
            
            $jo_stmt = $pdo->prepare("
                INSERT INTO job_orders (
                    job_order_id, station_id, customer_name, contact_number,
                    vehicle_plate, vehicle_type, vehicle_brand, vehicle_model,
                    service_type, description, mechanic_id, total_cost,
                    status, staff_id, shift_period, shift_name, shift_id,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    'Pending', ?, ?, ?, ?,
                    NOW(), NOW()
                )
            ");
            
            $jo_stmt->execute([
                $jo_number, $station_id, $customer_name, $contact_number,
                $plate_number, $vehicle_type, $vehicle_brand, $vehicle_model,
                $service_category, $service_description, 
                $assigned_technician > 0 ? $assigned_technician : null, $labor_cost,
                $me['id'], $shift['shift_period'], $shift['shift_name'], $shift['id']
            ]);
            
            $job_order_id = $pdo->lastInsertId();
        }
        
        // ── STEP 5: PROCESS MERCHANDISE ──────────────────────────────
        $merchandise_total = 0.00;
        $merchandise_items = $input['merchandise_items'] ?? [];
        
        if (in_array($transaction_type, ['merchandise', 'combined'])) {
            if (empty($merchandise_items)) {
                throw new Exception('At least one merchandise item is required');
            }
            
            foreach ($merchandise_items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);
                $unit_price = (float)($item['unit_price'] ?? 0);
                $line_total = $quantity * $unit_price;
                $merchandise_total += $line_total;
                
                if ($quantity <= 0) continue;
                
                // Check inventory availability
                $inv_check = $pdo->prepare("
                    SELECT stock_level FROM station_inventory 
                    WHERE product_id = ? AND station_id = ?
                ");
                $inv_check->execute([$product_id, $station_id]);
                $current_stock = (int)$inv_check->fetchColumn();
                
                if ($current_stock < $quantity) {
                    $product_name = $item['product_name'] ?? 'Product';
                    throw new Exception("Insufficient stock for {$product_name}. Available: {$current_stock}");
                }
            }
        }
        
        // ── STEP 6: PROCESS PAYMENT ──────────────────────────────────
        $payment_method = $input['payment_method'] ?? 'Cash';
        $payment_status = $input['payment_status'] ?? 'Paid';
        $amount_paid = (float)($input['amount_paid'] ?? 0);
        $total_amount = $merchandise_total + $labor_cost;
        $change_amount = max(0, $amount_paid - $total_amount);
        
        // Payment validation
        if ($payment_status === 'Paid' && $amount_paid < $total_amount) {
            throw new Exception('Amount paid is less than total amount');
        }
        
        // ── STEP 7: SAVE TRANSACTION ─────────────────────────────────
        $remarks = trim($input['remarks'] ?? '');
        
        $txn_stmt = $pdo->prepare("
            INSERT INTO merchandise_transactions (
                transaction_id, station_id, staff_id,
                customer_name, contact_number, address,
                vehicle_plate, vehicle_type, vehicle_brand, vehicle_model,
                item_sku, quantity, unit_price, total_amount,
                payment_method, payment_status, amount_tendered, change_amount,
                validation_status, shift_period, shift_name, shift_id,
                job_order_id, job_order_db_id,
                staff_remarks, transaction_date, transaction_type,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                '', 0, 0, ?,
                ?, ?, ?, ?,
                'Pending', ?, ?, ?,
                ?, ?,
                ?, NOW(), ?,
                NOW(), NOW()
            )
        ");
        
        $txn_stmt->execute([
            $txn_id, $station_id, $me['id'],
            $customer_name, $contact_number, $address,
            $plate_number, $vehicle_type, $vehicle_brand, $vehicle_model,
            $total_amount,
            $payment_method, $payment_status, $amount_paid, $change_amount,
            $shift['shift_period'], $shift['shift_name'], $shift['id'],
            $jo_number, $job_order_id,
            $remarks, $transaction_type
        ]);
        
        $transaction_db_id = $pdo->lastInsertId();
        
        // ── STEP 8: UPDATE MERCHANDISE HISTORY & INVENTORY ───────────
        if (!empty($merchandise_items)) {
            foreach ($merchandise_items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $sku = trim($item['sku'] ?? '');
                $product_name = trim($item['product_name'] ?? '');
                $category = trim($item['category'] ?? 'General');
                $quantity = (int)($item['quantity'] ?? 0);
                $unit_price = (float)($item['unit_price'] ?? 0);
                $line_total = $quantity * $unit_price;
                
                if ($quantity <= 0) continue;
                
                // Insert transaction item
                $pdo->prepare("
                    INSERT INTO merchandise_transaction_items (
                        transaction_id, product_id, sku, product_name, category,
                        quantity, unit_price, line_total
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $transaction_db_id, $product_id, $sku, $product_name, $category,
                    $quantity, $unit_price, $line_total
                ]);
                
                // Deduct inventory (will be reversed if rejected)
                $pdo->prepare("
                    UPDATE station_inventory 
                    SET stock_level = stock_level - ?,
                        last_updated = NOW()
                    WHERE product_id = ? AND station_id = ?
                ")->execute([$quantity, $product_id, $station_id]);
                
                // Log inventory movement
                $pdo->prepare("
                    INSERT INTO inventory_movement_log (
                        station_id, product_id, movement_type, quantity,
                        reference_type, reference_id, performed_by, created_at
                    ) VALUES (?, ?, 'sale', ?, 'transaction', ?, ?, NOW())
                ")->execute([$station_id, $product_id, -$quantity, $transaction_db_id, $me['id']]);
            }
        }
        
        // ── STEP 9: GENERATE AUDIT TRAIL ─────────────────────────────
        $audit_details = "Transaction {$txn_id} created";
        $audit_details .= " | Customer: {$customer_name}";
        $audit_details .= " | Type: {$transaction_type}";
        $audit_details .= " | Amount: ₱" . number_format($total_amount, 2);
        $audit_details .= " | Payment: {$payment_method}";
        if ($jo_number) $audit_details .= " | JO: {$jo_number}";
        
        $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, log_type, action_type, action_details,
                entity_type, entity_id, station_id,
                ip_address, user_agent, created_at
            ) VALUES (?, 'TRANSACTION', 'Create', ?, 'merchandise_transactions', ?, ?, ?, ?, NOW())
        ")->execute([
            $me['id'], $audit_details, $transaction_db_id, $station_id,
            $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // ── STEP 10: LOG CALENDAR ACTIVITY ───────────────────────────
        try {
            $calendar_title = "{$transaction_type}: {$txn_id}";
            $calendar_desc = "Customer: {$customer_name} | Amount: ₱" . number_format($total_amount, 2);
            
            $pdo->prepare("
                INSERT INTO calendar_events (
                    station_id, event_type, event_title, event_description,
                    event_date, created_by, created_at
                ) VALUES (?, 'transaction', ?, ?, CURDATE(), ?, NOW())
            ")->execute([$station_id, $calendar_title, $calendar_desc, $me['id']]);
        } catch (Exception $e) {
            error_log("Calendar log error: " . $e->getMessage());
        }
        
                // ── STEP 11: SEND NOTIFICATIONS ──────────────────────────────
        notify_transaction_submission($pdo, (int)$station_id, [
            'transaction_id'    => $txn_id,
            'transaction_db_id' => (int)$transaction_db_id,
            'transaction_type'  => $transaction_type,
            'total_amount'      => (float)$total_amount,
            'customer_name'     => $customer_name,
            'staff_name'        => $me['name'] ?? $me['username'] ?? 'Staff',
            'shift_period'      => $shift['shift_name'] ?? ''
        ]);
        try {
            $notif_message = "Transaction {$txn_id} saved successfully. Amount: ₱" . number_format($total_amount, 2);
            
            $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, event_type,
                    severity, source_key, created_at
                ) VALUES (?, 'transaction', 'Transaction Saved', ?, 'transaction_created', 'low', ?, NOW())
            ")->execute([$me['id'], $notif_message, $txn_id]);
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
        }
        
        // ── STEP 12: GENERATE RECEIPT DATA ───────────────────────────
        $receipt_data = [
            'transaction_id' => $txn_id,
            'reference_no' => $reference_no,
            'job_order_no' => $jo_number,
            'date_time' => $timestamp,
            'customer_name' => $customer_name,
            'contact_number' => $contact_number,
            'vehicle_plate' => $plate_number,
            'vehicle_type' => $vehicle_type,
            'staff_name' => $me['name'] ?? $me['username'],
            'shift' => $shift['shift_name'],
            'merchandise_items' => $merchandise_items,
            'labor_cost' => $labor_cost,
            'subtotal' => $merchandise_total,
            'total_amount' => $total_amount,
            'payment_method' => $payment_method,
            'amount_paid' => $amount_paid,
            'change' => $change_amount
        ];
        
        // ── COMMIT TRANSACTION ───────────────────────────────────────
        $pdo->commit();
        
        // ── RETURN SUCCESS ───────────────────────────────────────────
        echo json_encode([
            'success' => true,
            'message' => 'Transaction saved successfully',
            'transaction_id' => $txn_id,
            'transaction_db_id' => $transaction_db_id,
            'job_order_id' => $jo_number,
            'receipt_data' => $receipt_data,
            'notifications' => [
                'Transaction Saved Successfully',
                'Receipt Generated',
                'Inventory Updated',
                'Audit Trail Logged'
            ]
        ]);
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
