<?php
if (!function_exists('sanitize_optional_field')) {
    function sanitize_optional_field(?string $val): string {
        if ($val === null) return 'N/A';
        $trimmed = trim($val);
        if ($trimmed === '') return 'N/A';
        $lower = strtolower($trimmed);
        $invalid_placeholders = ['none', 'null', 'n/a', '-', 'unknown', 'not available', 'not_available', 'undefined', 'n.a.', 'n/a.'];
        if (in_array($lower, $invalid_placeholders, true)) {
            return 'N/A';
        }
        return $trimmed;
    }
}
/**
 * Staff Transaction Processor
 * Handles: Job Order, Merchandise, Job Order + Merchandise
 * Auto-updates: Job Order Tracker, Merchandise History, Inventory, Audit Trail, Receipt, Calendar
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$transaction_type = $input['transaction_type'] ?? 'merchandise'; // job_order | merchandise | combined

try {
    $pdo->beginTransaction();
    
    // ── 1. VALIDATE TRANSACTION DATA ────────────────────────────────────────
    $customer_first_name = trim($input['customer_first_name'] ?? '');
    $customer_last_name = sanitize_optional_field($input['customer_last_name'] ?? '');
    $customer_name = trim("$customer_first_name $customer_last_name");
    
    if (empty($customer_name)) {
        throw new Exception("Customer name is required");
    }
    
    $vehicle_plate = trim($input['vehicle_plate'] ?? '');
    $vehicle_type = trim($input['vehicle_type'] ?? '');
    $payment_method = $input['payment_method'] ?? 'Cash';
    $payment_status = $input['payment_status'] ?? 'Paid';
    $remarks = trim($input['remarks'] ?? '');
    
    // Get current shift
    $shift_stmt = $pdo->prepare("SELECT shift_period, shift_name, id FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $shift_stmt->execute([$me['id']]);
    $shift_data = $shift_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift_data) {
        // Auto clock-in based on user profile's assigned_shift or time-based fallback
        try {
            $user_assigned_shift = strtolower(trim((string)($me['assigned_shift'] ?? '')));
            if (strpos($user_assigned_shift, 'shift 1') !== false || strpos($user_assigned_shift, '1') !== false || $user_assigned_shift === 'first') {
                $auto_shift_key = 'first';
            } elseif (strpos($user_assigned_shift, 'shift 2') !== false || strpos($user_assigned_shift, '2') !== false || $user_assigned_shift === 'second') {
                $auto_shift_key = 'second';
            } else {
                $login_time = date('H:i:s');
                $auto_shift_key = ($login_time >= '06:00:00' && $login_time < '14:00:00') ? 'first' : 'second';
            }

            // Try to load exact DB record for consistent naming
            $sp = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE shift_key = ? AND is_active = 1 LIMIT 1");
            $sp->execute([$auto_shift_key]);
            $shift = $sp->fetch(PDO::FETCH_ASSOC);

            // Hard fallback if table is empty or record missing
            if (!$shift) {
                $shift = $auto_shift_key === 'first'
                    ? ['shift_key' => 'first',  'shift_name' => 'First Shift: 6:00 AM – 2:00 PM']
                    : ['shift_key' => 'second', 'shift_name' => 'Second Shift: 2:00 PM – 12:00 Midnight'];
            }

            $pdo->prepare(
                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                 VALUES (?, ?, NOW(), ?, ?)"
            )->execute([$me['id'], $station_id, $shift['shift_key'], $shift['shift_name']]);
            
            $new_session_id = $pdo->lastInsertId();
            
            $shift_data = [
                'shift_period' => $shift['shift_key'],
                'shift_name' => $shift['shift_name'],
                'id' => $new_session_id
            ];
        } catch (Exception $session_err) {
            throw new Exception("No active shift found, and auto-clock in failed: " . $session_err->getMessage());
        }
    }
    
    $shift_period = $shift_data['shift_period'];
    $shift_name = $shift_data['shift_name'];
    $shift_id = $shift_data['id'];
    
    $transaction_id = null;
    $job_order_id = null;
    $receipt_data = [];
    
    // ── 2. PROCESS BASED ON TRANSACTION TYPE ────────────────────────────────
    if ($transaction_type === 'job_order' || $transaction_type === 'combined') {
        // ── A. CREATE JOB ORDER ──────────────────────────────────────────────
        $service_type = trim($input['service_type'] ?? '');
        $service_description = trim($input['service_description'] ?? '');
        $mechanic_id = (int)($input['mechanic_id'] ?? 0);
        $contact_number = sanitize_optional_field($input['contact_number'] ?? '');
        
        if (empty($service_type)) {
            throw new Exception("Service type is required for Job Order");
        }
        
        // Generate Job Order ID
        $jo_id_str = 'JO' . date('Y') . $station_id . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // Calculate service cost (from service items)
        $service_items = $input['service_items'] ?? [];
        $service_total = 0.00;
        foreach ($service_items as $item) {
            $service_total += (float)($item['cost'] ?? 0);
        }
        
        // Insert Job Order
        $jo_stmt = $pdo->prepare("
            INSERT INTO job_orders (
                job_order_id, station_id, customer_name, customer_first_name, customer_last_name,
                vehicle_plate, vehicle_type, service_type, description,
                mechanic_id, contact_number, total_cost, status,
                payment_method, payment_status, staff_id, shift_period, shift_name, shift_id,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, 'Pending',
                ?, ?, ?, ?, ?, ?,
                NOW(), NOW()
            )
        ");
        
        $jo_stmt->execute([
            $jo_id_str, $station_id, $customer_name, $customer_first_name, $customer_last_name,
            $vehicle_plate, $vehicle_type, $service_type, $service_description,
            $mechanic_id > 0 ? $mechanic_id : null, $contact_number, $service_total,
            $payment_method, $payment_status, $me['id'], $shift_period, $shift_name, $shift_id
        ]);
        
        $job_order_id = $pdo->lastInsertId();
        
        // Insert service items
        foreach ($service_items as $item) {
            $item_name = trim($item['name'] ?? '');
            $item_cost = (float)($item['cost'] ?? 0);
            if (empty($item_name)) continue;
            
            $pdo->prepare("INSERT INTO job_order_items (job_order_id, item_name, item_cost) VALUES (?, ?, ?)")
                ->execute([$job_order_id, $item_name, $item_cost]);
        }
        
        // Log to audit trail
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, station_id, ip_address, user_agent, created_at)
            VALUES (?, 'JOB_ORDER', 'Create', ?, 'job_orders', ?, ?, ?, ?, NOW())
        ")->execute([
            $me['id'],
            "Job Order {$jo_id_str} created for {$customer_name}",
            $job_order_id,
            $station_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }
    
    if ($transaction_type === 'merchandise' || $transaction_type === 'combined') {
        // ── B. CREATE MERCHANDISE TRANSACTION ────────────────────────────────
        $merchandise_items = $input['merchandise_items'] ?? [];
        
        if (empty($merchandise_items)) {
            throw new Exception("At least one merchandise item is required");
        }
        
        // Generate Transaction ID
        $txn_id_str = 'MERCH' . date('Y') . $station_id . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        
        // Calculate totals
        $subtotal = 0.00;
        $total_quantity = 0;
        foreach ($merchandise_items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['unit_price'] ?? 0);
            $subtotal += $qty * $price;
            $total_quantity += $qty;
        }
        
        $vat_rate = 0.12;
        $vat_amount = $subtotal * $vat_rate;
        $total_amount = $subtotal + $vat_amount;
        
        // Payment details
        $amount_tendered = (float)($input['amount_tendered'] ?? 0);
        $change_amount = max(0, $amount_tendered - $total_amount);
        
        // Insert merchandise transaction header
        $mt_stmt = $pdo->prepare("
            INSERT INTO merchandise_transactions (
                transaction_id, station_id, staff_id, transaction_date,
                customer_name, customer_first_name, customer_last_name,
                item_sku, quantity, unit_price, total_amount,
                subtotal_amount, vat_amount,
                payment_method, payment_status,
                amount_tendered, change_amount,
                validation_status, shift_period, shift_name, shift_id,
                staff_remarks, job_order_id, job_order_db_id,
                vehicle_plate, vehicle_type,
                created_at, updated_at, transaction_type
            ) VALUES (
                ?, ?, ?, NOW(),
                ?, ?, ?,
                '', ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                'Official', ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                NOW(), NOW(), ?
            )
        ");
        
        $mt_stmt->execute([
            $txn_id_str, $station_id, $me['id'],
            $customer_name, $customer_first_name, $customer_last_name,
            $total_quantity, 0, $total_amount,
            $subtotal, $vat_amount,
            $payment_method, $payment_status,
            $amount_tendered, $change_amount,
            $shift_period, $shift_name, $shift_id,
            $remarks, $job_order_id ? $jo_id_str : null, $job_order_id,
            $vehicle_plate, $vehicle_type,
            $transaction_type
        ]);
        
        $transaction_id = $pdo->lastInsertId();

        foreach ($merchandise_items as $item) {
            $product_id = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);

            if ($product_id <= 0 || $qty <= 0) {
                continue;
            }

            $stock_stmt = $pdo->prepare("
                SELECT stock_level
                FROM station_inventory
                WHERE station_id = ? AND product_id = ?
                FOR UPDATE
            ");
            $stock_stmt->execute([$station_id, $product_id]);
            $stock_level = $stock_stmt->fetchColumn();

            if ($stock_level === false) {
                throw new Exception("Inventory record is missing for product ID {$product_id}");
            }
            if ((float)$stock_level < $qty) {
                throw new Exception("Insufficient stock for product ID {$product_id}. Available: {$stock_level}, requested: {$qty}");
            }
        }
        
        // Insert merchandise items
        foreach ($merchandise_items as $item) {
            $product_id = (int)($item['product_id'] ?? 0);
            $sku = trim($item['sku'] ?? '');
            $product_name = trim($item['product_name'] ?? '');
            $qty = (int)($item['quantity'] ?? 0);
            $unit_price = (float)($item['unit_price'] ?? 0);
            $line_total = $qty * $unit_price;
            
            if ($qty <= 0) continue;
            
            $pdo->prepare("
                INSERT INTO merchandise_transaction_items (
                    transaction_id, product_id, sku, product_name,
                    quantity, unit_price, line_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $transaction_id, $product_id, $sku, $product_name,
                $qty, $unit_price, $line_total
            ]);

            if ($product_id > 0 && $qty > 0) {
                $pdo->prepare("
                    UPDATE station_inventory
                    SET stock_level = stock_level - ?,
                        last_updated = NOW()
                    WHERE station_id = ? AND product_id = ?
                ")->execute([$qty, $station_id, $product_id]);
            }
        }

        try {
            $pdo->prepare("UPDATE merchandise_transactions SET inventory_deducted = 1 WHERE id = ? AND station_id = ?")
                ->execute([$transaction_id, $station_id]);
        } catch (Exception $inventoryFlagError) {
            error_log("Inventory deducted flag warning: " . $inventoryFlagError->getMessage());
        }
        
        // Log to audit trail
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, station_id, ip_address, user_agent, created_at)
            VALUES (?, 'TRANSACTION', 'Create', ?, 'merchandise_transactions', ?, ?, ?, ?, NOW())
        ")->execute([
            $me['id'],
            "Merchandise transaction {$txn_id_str} created for {$customer_name} - Amount: ₱" . number_format($total_amount, 2),
            $transaction_id,
            $station_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }
    
    // ── 3. AUTO-UPDATE CALENDAR (Task Logging) ──────────────────────────────
    if ($job_order_id) {
        try {
            $pdo->prepare("
                INSERT INTO calendar_events (
                    station_id, event_type, event_title, event_description,
                    event_date, created_by, created_at
                ) VALUES (?, 'job_order', ?, ?, CURDATE(), ?, NOW())
            ")->execute([
                $station_id,
                "Job Order: {$jo_id_str}",
                "Service: {$service_type} | Customer: {$customer_name}",
                $me['id']
            ]);
        } catch (Exception $e) {
            // Calendar table may not exist - log but don't fail
            error_log("Calendar event error: " . $e->getMessage());
        }
    }
    
    // ── 4. COMMIT TRANSACTION ────────────────────────────────────────────────
    $pdo->commit();

    // ── 4b. POST-COMMIT: Credit Account → Create AR record ──────────────────
    $pm_lower = strtolower(trim($payment_method));
    if (in_array($pm_lower, ['credit account','credit','ar','account receivable'])) {
        try {
            $amt = $total_amount ?? $service_total ?? 0;
            $txn_str = $txn_id_str ?? $jo_id_str ?? '';
            $cid = $input['customer_id'] ?? null;
            $or_no = 'OR-' . date('Y') . '-' . str_pad($transaction_id ?? 0, 6, '0', STR_PAD_LEFT);

            // Ensure station_id column exists
            try { $pdo->exec("ALTER TABLE customer_accounts_receivable ADD COLUMN IF NOT EXISTS station_id INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}

            $pdo->prepare("
                INSERT INTO customer_accounts_receivable
                (customer_id, transaction_id, transaction_db_id, or_number, total_amount, amount_paid, outstanding_balance, status, station_id, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, 'Active', ?, NOW())
            ")->execute([$cid, $txn_str, $transaction_id, $or_no, $amt, $amt, $station_id]);
        } catch (Exception $are) {
            error_log('AR record creation error: ' . $are->getMessage());
        }
    }

    // ── 4c. Structured Audit Trail: Transaction Created ──────────────────────
    try {
        require_once __DIR__ . '/audit_logging.php';
        log_structured_audit([
            'user_id'        => $me['id'],
            'user_role'      => $role,
            'action'         => 'Transaction Created',
            'module'         => 'Transactions',
            'transaction_id' => $txn_id_str ?? $jo_id_str ?? '',
            'or_number'      => 'OR-' . date('Y') . '-' . str_pad($transaction_id ?? 0, 6, '0', STR_PAD_LEFT),
            'new_values'     => [
                'transaction_type' => $transaction_type,
                'payment_method'   => $payment_method,
                'payment_status'   => $payment_status,
                'total_amount'     => $total_amount ?? $service_total ?? 0,
                'customer'         => $customer_name
            ],
            'station_id'     => $station_id
        ]);
    } catch (Exception $ate) { error_log('Audit trail error: ' . $ate->getMessage()); }

        // ── Single notification to manager for this transaction ──
    notify_transaction_submission($pdo, (int)$station_id, [
        'transaction_id'    => $txn_id_str ?? $jo_id_str ?? '',
        'transaction_db_id' => (int)($transaction_id ?? $job_order_id ?? 0),
        'transaction_type'  => $transaction_type,
        'total_amount'      => (float)($total_amount ?? $service_total ?? 0),
        'customer_name'     => $customer_name,
        'staff_name'        => $me['name'] ?? $me['username'] ?? 'Staff',
        'shift_period'      => $shift_period ?? $shift_name ?? ''
    ]);

    // ── 5. PREPARE RECEIPT DATA ──────────────────────────────────────────────

    $receipt_data = [
        'transaction_id' => $txn_id_str ?? null,
        'job_order_id' => $jo_id_str ?? null,
        'customer_name' => $customer_name,
        'transaction_date' => date('Y-m-d H:i:s'),
        'staff_name' => $me['name'] ?? $me['username'],
        'station_id' => $station_id,
        'shift_period' => $shift_period,
        'payment_method' => $payment_method,
        'subtotal' => $subtotal ?? 0,
        'vat_amount' => $vat_amount ?? 0,
        'total_amount' => $total_amount ?? $service_total ?? 0,
        'amount_tendered' => $amount_tendered ?? 0,
        'change_amount' => $change_amount ?? 0,
        'items' => $merchandise_items ?? [],
        'service_items' => $service_items ?? []
    ];
    
    // ── 6. SEND SUCCESS RESPONSE ─────────────────────────────────────────────
    echo json_encode([
        'success' => true,
        'message' => 'Transaction saved successfully',
        'transaction_id' => $transaction_id,
        'job_order_id' => $job_order_id,
        'receipt_data' => $receipt_data
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
