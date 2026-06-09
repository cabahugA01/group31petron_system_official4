<?php
$page_id = 'job_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('job_orders')) {
    render_module_disabled_page('Job Orders');
}

// Load merchandise inventory for the Other service parts dropdown
$merch_inventory = [];
try {
    $stmt = $pdo->prepare("SELECT id, product_name, stock_level, unit FROM inventory WHERE station_id = ? AND type = 'merch' AND stock_level > 0 ORDER BY product_name");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // table may not have unit column — fallback
    try {
        $stmt = $pdo->prepare("SELECT id, product_name, stock_level FROM inventory WHERE station_id = ? AND type = 'merch' AND stock_level > 0 ORDER BY product_name");
        $stmt->execute([$station_id]);
        $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) { $merch_inventory = []; }
}

// Only staff can access job orders
if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    unset($_SESSION['error']); 
}

// Helper Functions
function generateJobOrderId($station_id) {
    $date = date('Ymd');
    $sequence = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    return "JO-{$date}-{$sequence}";
}

function getCurrentShiftId() {
    $hour = (int)date('H');
    if ($hour >= 6 && $hour < 14) return 1;  // Morning shift 6AM-2PM
    if ($hour >= 14 && $hour < 22) return 2; // Afternoon shift 2PM-10PM
    return 3; // Night shift 10PM-6AM
}

function calculateServiceCost($service_type, $estimated_duration, $pdo = null) {
    // Database-driven service cost calculation (PER SERVICE PRICING)
    if ($pdo === null) {
        global $pdo;
    }
    
    try {
        // Get service price from database (flat rate per service)
        $sql = "SELECT service_price FROM job_order_service_types WHERE service_name = ? AND active = TRUE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$service_type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $service_price = $result['service_price'] ?? 400.00; // Default rate
    } catch (Exception $e) {
        // Fallback to default rate if database fails
        $service_price = 400.00;
    }
    
    // Return flat rate per service (duration no longer matters for pricing)
    return round($service_price, 2);
}

function validateStatusTransition($current_status, $new_status, $role) {
    // Define valid transitions
    $valid_transitions = [
        'Pending Validation' => ['Approved', 'Rejected'],
        'Pending' => ['In Progress', 'Completed', 'Cancelled'], // Approved job orders start as 'Pending'
        'In Progress' => ['Completed', 'Cancelled'],
        'Completed' => [], // Final state
        'Rejected' => [], // Final state
        'Cancelled' => [] // Final state
    ];
    
    // Staff can update approved job orders (validation_status = 'Approved') regardless of status field
    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // Allow staff to move approved job orders to In-Progress or Completed
        // This handles cases where status field might be empty or inconsistent
        $allowed_transitions = ['In Progress', 'Completed'];
        if (in_array($new_status, $allowed_transitions)) {
            return true; // Allow staff to update approved job orders
        }
        
        // Staff can complete in-progress job orders
        if ($current_status === 'In Progress' && $new_status === 'Completed') {
            return true;
        }
        return false;
    }
    
    
    return in_array($new_status, $valid_transitions[$current_status] ?? []);
}

function checkMechanicBusyStatus($mechanic_id, $pdo) {
    try {
        // Check if mechanic has active job orders (In-Progress status)
        $stmt = $pdo->prepare("
            SELECT job_order_id, status, created_at
            FROM job_orders 
            WHERE assigned_mechanic_id = ? AND status IN ('Pending', 'In Progress')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$mechanic_id]);
        $active_job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($active_job) {
            return [
                'busy' => true,
                'job_order_id' => $active_job['job_order_id'],
                'status' => $active_job['status'],
                'created_at' => $active_job['created_at']
            ];
        }
        
        return ['busy' => false];
    } catch (Exception $e) {
        // If there's an error, assume not busy to allow workflow
        return ['busy' => false];
    }
}

// Handle AJAX requests for mechanic status check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['ajax_action']) {
        case 'check_mechanic_status':
            $mechanic_id = $_POST['mechanic_id'] ?? 0;
            $status = checkMechanicBusyStatus($mechanic_id, $pdo);
            echo json_encode($status);
            exit;
            
        case 'log_staff_override':
            $mechanic_id = $_POST['mechanic_id'] ?? 0;
            $job_order_id = $_POST['job_order_id'] ?? '';
            $override_reason = $_POST['override_reason'] ?? '';
            
            try {
                // Log the staff override to audit trail
                $stmt = $pdo->prepare("
                    INSERT INTO job_order_audit (
                        job_order_id, action, before_status, after_status,
                        performed_by, performed_at, notes, ip_address, user_agent
                    ) VALUES (?, 'STAFF_OVERRIDE', 'Busy', 'Override', ?, NOW(), ?, ?, ?)
                ");
                $stmt->execute([
                    $job_order_id, 
                    $me['id'], 
                    "Staff override: Assigned to busy mechanic ID {$mechanic_id}. Reason: {$override_reason}",
                    $_SERVER['REMOTE_ADDR'] ?? '', 
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Override logged successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to log override: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_job_order':
                $customer_name = $_POST['customer_name'] ?? '';
                $customer_id = $_POST['credit_customer_id'] ?? null;
                
                // Handle multiple service types from checkboxes
                $service_types = $_POST['service_types'] ?? [];
                $service_type_other = $_POST['service_type_other'] ?? '';
                
                // Combine selected service types into a single string for storage
                $service_type = '';
                if (!empty($service_types)) {
                    // Add selected service types
                    $service_type_array = $service_types;
                    
                    // Add custom service type if provided
                    if (!empty($service_type_other)) {
                        $service_type_array[] = $service_type_other;
                    }
                    
                    $service_type = implode(', ', $service_type_array);
                } elseif (!empty($service_type_other)) {
                    // Only custom service type
                    $service_type = $service_type_other;
                }
                // Validate that at least one service type is selected
                if (empty($service_types) && empty($service_type_other)) {
                    $_SESSION['error'] = 'Please select at least one service type.';
                    header('Location: joborder.php');
                    exit;
                }
                
                $mechanic_id = $_POST['assigned_mechanic_id'] ?? '';
                $vehicle_plate = $_POST['vehicle_plate'] ?? '';
                $vehicle_type = $_POST['vehicle_type'] ?? '';
                
                // Handle required parts - new checkbox-based system
                $required_parts_checkboxes = $_POST['required_parts_checkboxes'] ?? [];
                $required_parts_qty        = $_POST['required_parts_qty'] ?? [];
                $required_parts_remarks    = $_POST['required_parts_remarks'] ?? [];
                $required_parts_service    = $_POST['required_parts_service'] ?? [];
                $required_parts_price      = $_POST['required_parts_price'] ?? [];

                // Manual service flag
                $is_manual_service  = (int)($_POST['is_manual_service'] ?? 0);
                $service_type_other = trim($_POST['service_type_other'] ?? '');
                
                // Handle manual parts
                $manual_parts_name    = $_POST['manual_parts_name'] ?? [];
                $manual_parts_qty     = $_POST['manual_parts_qty'] ?? [];
                $manual_parts_remarks = $_POST['manual_parts_remarks'] ?? [];
                $manual_parts_service = $_POST['manual_parts_service'] ?? [];
                $manual_parts_price   = $_POST['manual_parts_price'] ?? [];
                
                // Build final required parts array — single pass, no duplicates
                $final_required_parts = [];
                
                // Process checked auto-populated parts
                if (!empty($required_parts_checkboxes)) {
                    foreach ($required_parts_checkboxes as $index => $part_name) {
                        $qty         = isset($required_parts_qty[$index])     ? (int)$required_parts_qty[$index]   : 1;
                        $remarks     = isset($required_parts_remarks[$index]) ? $required_parts_remarks[$index]     : '';
                        $service_key = isset($required_parts_service[$index]) ? $required_parts_service[$index]     : 'general';
                        $unit_price  = isset($required_parts_price[$index])   ? (float)$required_parts_price[$index] : 0;
                        
                        $final_required_parts[] = [
                            'name'         => $part_name,
                            'qty'          => $qty,
                            'unit_price'   => $unit_price,
                            'remarks'      => $remarks,
                            'type'         => 'auto_populated',
                            'service_type' => $service_key,
                            'manual_input' => false
                        ];
                    }
                }
                
                // Process manual parts (from Other service section)
                if (!empty($manual_parts_name)) {
                    foreach ($manual_parts_name as $index => $part_name) {
                        if (!empty(trim($part_name))) {
                            $qty         = isset($manual_parts_qty[$index])     ? (int)$manual_parts_qty[$index]     : 1;
                            $remarks     = isset($manual_parts_remarks[$index]) ? $manual_parts_remarks[$index]       : '';
                            $svc_key     = isset($manual_parts_service[$index]) ? $manual_parts_service[$index]       : ($is_manual_service ? 'other' : 'general');
                            $unit_price  = isset($manual_parts_price[$index])   ? (float)$manual_parts_price[$index]  : 0;
                            
                            $final_required_parts[] = [
                                'name'         => trim($part_name),
                                'qty'          => $qty,
                                'unit_price'   => $unit_price,
                                'remarks'      => $remarks,
                                'type'         => 'manual',
                                'service_type' => $svc_key,
                                'manual_input' => true
                            ];
                        }
                    }
                }
                
                // Convert to string for database storage
                $required_parts_json = json_encode($final_required_parts);
                
                // Log parts selection for audit trail — per service type breakdown
                $parts_by_service = [];
                foreach ($final_required_parts as $part) {
                    $svc = $part['service_type'] ?? 'general';
                    $parts_by_service[$svc][] = $part;
                }

                $audit_data = [
                    'action'           => 'parts_selection',
                    'staff_id'         => $_SESSION['user_id'] ?? null,
                    'staff_name'       => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'unknown'),
                    'station_id'       => $station_id,
                    'service_types'    => $service_type,
                    'is_manual_service'=> (bool)$is_manual_service,
                    'custom_service'   => $is_manual_service ? $service_type_other : null,
                    'parts_by_service' => $parts_by_service,
                    'total_parts'      => count($final_required_parts),
                    'manual_parts'     => count(array_filter($final_required_parts, fn($p) => $p['manual_input'] ?? false)),
                    'note'             => $is_manual_service
                                            ? 'Manual service type and parts encoded by staff.'
                                            : 'Standard service type with auto-populated parts.',
                    'timestamp'        => date('Y-m-d H:i:s'),
                    'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ];
                
                $audit_stmt = $pdo->prepare("
                    INSERT INTO audit_log (action, user_id, station_id, details, ip_address) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $audit_stmt->execute([
                    $audit_data['action'],
                    $audit_data['staff_id'],
                    $audit_data['station_id'],
                    json_encode($audit_data),
                    $audit_data['ip_address']
                ]);
                $service_description = $_POST['service_description'] ?? '';
                $estimated_duration = $_POST['estimated_duration'] ?? 60;
                $additional_notes   = $_POST['additional_notes']   ?? '';
                $payment_method     = $_POST['payment_method']     ?? '';

                // Block locked or inactive customers from credit purchases
                if ($payment_method === 'Credit' && !empty($customer_id)) {
                    $cust_stmt = $pdo->prepare("SELECT status FROM customers WHERE id = ? LIMIT 1");
                    $cust_stmt->execute([$customer_id]);
                    $cust_status = $cust_stmt->fetchColumn();
                    if ($cust_status === 'locked') {
                        $_SESSION['error'] = 'Transaction blocked: Customer account is locked.';
                        header('Location: joborder.php');
                        exit;
                    }
                    if ($cust_status === 'inactive') {
                        $_SESSION['error'] = 'Transaction blocked: Customer account is inactive.';
                        header('Location: joborder.php');
                        exit;
                    }
                }

                // ── Collect payment method-specific reference fields ──────────
                $card_ref       = trim($_POST['card_ref']       ?? '');
                $ewallet_ref    = trim($_POST['ewallet_ref']    ?? '');
                $efuel_card_id  = trim($_POST['efuel_card_id']  ?? '');
                $credit_cust    = trim($_POST['credit_customer_name'] ?? '');

                // ── Payment amounts from hidden inputs ────────────────────────
                $labor_cost   = (float)($_POST['labor_cost']   ?? 0);
                $parts_cost   = (float)($_POST['parts_cost']   ?? 0);
                $amount_paid  = (float)($_POST['amount_paid']  ?? 0);
                $total_amount = (float)($_POST['total_amount'] ?? 0);
                $sukli        = 0;
                $payment_status = 'Pending';
                
                // Calculate total amount using custom prices set by staff
                $service_cost = 0;
                $service_price_details = [];
                
                // Process custom prices for each selected service
                foreach ($service_types as $selected_service) {
                    // Find the service key for this service name
                    $service_key = null;
                    foreach ($service_types_with_parts as $service) {
                        if ($service['service_name'] === $selected_service) {
                            $service_key = $service['service_key'];
                            break;
                        }
                    }

                    // Handle "Other" manual service price
                    if ($service_key === 'other' || strtolower($selected_service) === 'other (manual input for non-listed services)') {
                        $other_price = (float)($_POST['service_price_other'] ?? 0);
                        if ($other_price > 0) {
                            $service_cost += $other_price;
                            $service_price_details[] = [
                                'service_name'  => $service_type_other ?: 'Other (Manual)',
                                'service_key'   => 'other',
                                'custom_price'  => $other_price,
                                'manual_input'  => true
                            ];
                        }
                        continue;
                    }
                    
                    if ($service_key) {
                        // Get custom price from form submission
                        $custom_price = $_POST["service_price_{$service_key}"] ?? null;
                        
                        if ($custom_price !== null && is_numeric($custom_price)) {
                            $price = (float)$custom_price;
                            
                            // Validate price is within range
                            $stmt = $pdo->prepare("SELECT min_price, max_price FROM job_order_service_types WHERE service_key = ? AND active = TRUE");
                            $stmt->execute([$service_key]);
                            $price_range = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($price_range && $price >= $price_range['min_price'] && $price <= $price_range['max_price']) {
                                $service_cost += $price;
                                $service_price_details[] = [
                                    'service_name' => $selected_service,
                                    'service_key' => $service_key,
                                    'custom_price' => $price,
                                    'min_price' => $price_range['min_price'],
                                    'max_price' => $price_range['max_price']
                                ];
                            } else {
                                // Use default price if custom price is out of range
                                $default_price = calculateServiceCost($selected_service, $estimated_duration);
                                $service_cost += $default_price;
                                $service_price_details[] = [
                                    'service_name' => $selected_service,
                                    'service_key' => $service_key,
                                    'custom_price' => $default_price,
                                    'min_price' => $price_range['min_price'] ?? 0,
                                    'max_price' => $price_range['max_price'] ?? 0,
                                    'price_error' => 'Custom price out of range, used default'
                                ];
                            }
                        } else {
                            // Use default price if no custom price provided
                            $default_price = calculateServiceCost($selected_service, $estimated_duration);
                            $service_cost += $default_price;
                            $service_price_details[] = [
                                'service_name' => $selected_service,
                                'service_key' => $service_key,
                                'custom_price' => $default_price,
                                'min_price' => 0,
                                'max_price' => 0,
                                'price_error' => 'No custom price provided, used default'
                            ];
                        }
                    }
                }
                
                $parts_cost = (float)($_POST['parts_cost'] ?? 0);   // Use value calculated by JS
                $total_amount = $service_cost + $parts_cost;
                
                // Process payment based on method
                switch ($payment_method) {
                    case 'Cash':
                        if ($amount_paid > 0) {
                            $sukli = max(0, $amount_paid - $total_amount);
                            $payment_status = $amount_paid >= $total_amount ? 'Paid' : 'Insufficient Payment';
                        } else {
                            $payment_status = 'Pending';
                        }
                        $audit_payment_note = "Cash payment committed. Tendered: ₱{$amount_paid}, Change: ₱{$sukli}.";
                        break;

                    case 'Card':
                        $amount_paid    = $total_amount;
                        $sukli          = 0;
                        $payment_status = 'Paid';
                        $audit_payment_note = "Card payment committed." . ($card_ref ? " Ref: {$card_ref}." : '');
                        break;

                    case 'E-Wallet':
                        $amount_paid    = $total_amount;
                        $sukli          = 0;
                        $payment_status = 'Paid';
                        $audit_payment_note = "E-Wallet payment committed." . ($ewallet_ref ? " Ref: {$ewallet_ref}." : '');
                        break;

                    case 'E-Fuel Card':
                        $amount_paid    = $total_amount;
                        $sukli          = 0;
                        $payment_status = 'Paid';
                        $audit_payment_note = "E-Fuel Card payment committed." . ($efuel_card_id ? " Card ID: {$efuel_card_id}." : '');
                        break;

                    case 'Credit':
                        $amount_paid    = 0;
                        $sukli          = 0;
                        $payment_status = 'Pending Payment';
                        $audit_payment_note = "Credit transaction committed (Pending). Customer: " . ($credit_cust ?: $customer_name) . ".";
                        break;

                    default:
                        $payment_status     = 'Pending';
                        $audit_payment_note = "Payment method: {$payment_method}.";
                        break;
                }

                // Store reference fields in additional_notes if provided
                $ref_parts = array_filter([
                    $card_ref      ? "Card Ref: {$card_ref}"           : '',
                    $ewallet_ref   ? "E-Wallet Ref: {$ewallet_ref}"    : '',
                    $efuel_card_id ? "E-Fuel Card: {$efuel_card_id}"   : '',
                    $credit_cust   ? "Credit Customer: {$credit_cust}" : '',
                ]);
                if (!empty($ref_parts)) {
                    $additional_notes = trim($additional_notes . "\n" . implode(' | ', $ref_parts));
                }
                
                // Credit transaction handling
                $receivable_id = $_POST['receivable_id'] ?? '';
                $is_credit_transaction = ($payment_method === 'Credit') ? 'true' : 'false';
                
                try {
                    // Check mechanic busy status
                    $mechanic_status = checkMechanicBusyStatus($mechanic_id, $pdo);
                    $staff_override = $_POST['staff_override'] ?? 'false';
                    
                    // If mechanic is busy and no staff override, show error
                    if ($mechanic_status['busy'] && $staff_override === 'false') {
                        $_SESSION['error'] = "Mechanic is currently busy with Job Order #{$mechanic_status['job_order_id']}. Please confirm override or select another mechanic.";
                        header('Location: joborder.php');
                        exit;
                    }
                    
                    // Log staff override if applicable
                    if ($mechanic_status['busy'] && $staff_override === 'true') {
                        $override_reason = $_POST['override_reason'] ?? 'Staff proceeded with busy mechanic assignment';
                        
                        // We'll log this after creating the job order since we need the job_order_id
                        $should_log_override = true;
                    } else {
                        $should_log_override = false;
                    }
                    
                    // Auto-generate Job Order ID
                    $job_order_id = generateJobOrderId($station_id);
                    
                    // Get current shift ID
                    $shift_id = getCurrentShiftId();
                    
                    // ── Schema migrations — run once, idempotent ────────────────────────
                    $migrations = [
                        "ALTER TABLE job_orders MODIFY COLUMN service_type VARCHAR(500) NOT NULL DEFAULT ''",
                        "ALTER TABLE job_orders MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash'",
                        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS service_price_details TEXT NULL",
                        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS additional_notes TEXT NULL",
                        "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS assigned_by INT(11) NOT NULL DEFAULT 0",
                    ];
                    foreach ($migrations as $sql) {
                        try { $pdo->exec($sql); } catch (Exception $e) { /* column already correct */ }
                    }

                    // ── Map payment method to safe stored value ──────────────────────────
                    $payment_method_db = $payment_method ?: 'Cash';

                    // ── INSERT — only confirmed-existing + just-migrated columns ─────────
                    $stmt = $pdo->prepare("
                        INSERT INTO job_orders (
                            job_order_number, job_order_id,
                            customer_name, customer_id,
                            service_type, assigned_mechanic_id, assigned_by,
                            vehicle_plate, vehicle_type,
                            required_parts, service_description,
                            estimated_duration, notes, additional_notes,
                            payment_method, station_id, shift_id,
                            created_by, status, validation_status,
                            estimated_cost, amount_paid, sukli,
                            payment_status, service_price_details
                        ) VALUES (
                            ?, ?,
                            ?, ?,
                            ?, ?, ?,
                            ?, ?,
                            ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?
                        )
                    ");

                    $stmt->execute([
                        $job_order_id,        $job_order_id,
                        $customer_name,       $customer_id,
                        $service_type,        $mechanic_id,        $me['id'],
                        $vehicle_plate,       $vehicle_type,
                        $required_parts_json, $service_description,
                        $estimated_duration,  $additional_notes,   $additional_notes,
                        $payment_method_db,   $station_id,         $shift_id,
                        $me['id'],            'Pending Validation', 'Pending Validation',
                        $total_amount,        $amount_paid,        $sukli,
                        $payment_status,      json_encode($service_price_details),
                    ]);
                    
                    $database_id = $pdo->lastInsertId();

                    // ── Audit trail ───────────────────────────────────────────
                    $audit_notes  = "Job order {$job_order_id} created: {$service_type} for {$customer_name}.";
                    $audit_notes .= " Labor: ₱{$labor_cost} | Parts: ₱{$parts_cost} | Grand Total: ₱{$total_amount}.";
                    $audit_notes .= " " . ($audit_payment_note ?? "Payment: {$payment_method}.");
                    $stmt = $pdo->prepare("
                        INSERT INTO job_order_audit (
                            job_order_id, action, before_status, after_status,
                            performed_by, performed_at, notes, ip_address, user_agent
                        ) VALUES (?, 'CREATE', 'New', ?, ?, NOW(), ?, ?, ?)
                    ");
                    $stmt->execute([$database_id, $payment_status, $me['id'], $audit_notes,
                                    $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

                    if ($is_credit_transaction === 'true') {
                        // already captured in audit_payment_note
                    }

                    // Log staff override if applicable
                    if ($should_log_override) {
                        $override_reason = $_POST['override_reason'] ?? 'Staff proceeded with busy mechanic assignment';
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO job_order_audit (
                                job_order_id, action, before_status, after_status,
                                performed_by, performed_at, notes, ip_address, user_agent
                            ) VALUES (?, 'STAFF_OVERRIDE', 'Busy', 'Override', ?, NOW(), ?, ?, ?)
                        ");
                        $stmt->execute([
                            $database_id, 
                            $me['id'], 
                            "Staff override: Assigned to busy mechanic ID {$mechanic_id}. Reason: {$override_reason}. Conflicting Job Order: #{$mechanic_status['job_order_id']}",
                            $_SERVER['REMOTE_ADDR'] ?? '', 
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);
                    }
                    
                    // Auto-link to receivables for credit transactions
                    if ($is_credit_transaction === 'true' && !empty($customer_id) && !empty($receivable_id)) {
                        // Update existing receivable record with job order link
                        $stmt = $pdo->prepare("
                            UPDATE accounts_receivable 
                            SET job_order_id = ?, 
                                description = CONCAT(description, ' - Job Order: ', ?),
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $description = "Job Order {$job_order_id} - {$service_type} for {$customer_name}";
                        $stmt->execute([$database_id, $description, $receivable_id]);
                        
                        // Log auto-linking to audit trail
                        $stmt = $pdo->prepare("
                            INSERT INTO job_order_audit (
                                job_order_id, action, before_status, after_status,
                                performed_by, performed_at, notes, ip_address, user_agent
                            ) VALUES (?, 'AUTO_LINK_RECEIVABLES', 'Unlinked', 'Linked', ?, NOW(), ?, ?, ?)
                        ");
                        $stmt->execute([
                            $database_id, $me['id'], 
                            "Auto-linked to Receivables ID: {$receivable_id} for Customer ID: {$customer_id}",
                            $_SERVER['REMOTE_ADDR'] ?? '', 
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);
                    } elseif ($payment_method === 'Credit' && $customer_id) {
                        // Fallback: Create new receivable record for traditional credit payments
                        $estimated_cost = calculateServiceCost($service_type, $estimated_duration);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO accounts_receivable (
                                customer_id, job_order_id, reference_number, amount, 
                                due_date, station_id, created_by, created_at, status,
                                description, payment_terms
                            ) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?, NOW(), 'Pending', ?, ?)
                        ");
                        
                        $reference_number = 'AR-' . date('Ymd') . '-' . str_pad($database_id, 4, '0', STR_PAD_LEFT);
                        $description = "Job Order {$job_order_id} - {$service_type} for {$customer_name}";
                        $payment_terms = 'Net 30';
                        
                        $stmt->execute([
                            $customer_id, $database_id, $reference_number, $estimated_cost, 
                            $station_id, $me['id'], $description, $payment_terms
                        ]);
                        
                        // Update customer balance
                        $stmt = $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                        $stmt->execute([$estimated_cost, $customer_id]);
                        
                        // Record in customer_credit_transactions
                        try {
                            $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
                            $bal_stmt->execute([$customer_id]);
                            $new_bal = (float)$bal_stmt->fetchColumn();
                            
                            $cct_stmt = $pdo->prepare("
                                INSERT INTO customer_credit_transactions (
                                    customer_id, transaction_id, transaction_type, amount, 
                                    running_balance, description, station_id, created_by, created_at
                                ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                            ");
                            $cct_stmt->execute([
                                $customer_id,
                                $reference_number ?: ('JO-' . $job_order_id),
                                $estimated_cost,
                                $new_bal,
                                $description ?: "Job Order: {$job_order_id}",
                                $station_id,
                                $me['id']
                            ]);
                        } catch (Exception $ccError) {
                            error_log("Error inserting into customer_credit_transactions: " . $ccError->getMessage());
                        }
                        
                        // Update job order with estimated cost
                        $stmt = $pdo->prepare("UPDATE job_orders SET estimated_cost = ? WHERE id = ?");
                        $stmt->execute([$estimated_cost, $database_id]);
                        
                        // Log receivables linkage
                        $stmt = $pdo->prepare("
                            INSERT INTO job_order_audit (
                                job_order_id, action, performed_by, performed_at, notes, ip_address, user_agent
                            ) VALUES (?, 'RECEIVABLES_LINK', ?, NOW(), ?, ?, ?)
                        ");
                        $stmt->execute([
                            $database_id, $me['id'], 
                            "Job order {$job_order_id} linked to receivables: {$reference_number} - ₱{$estimated_cost}",
                            $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);
                    } else {
                        // Calculate estimated cost for non-credit orders too
                        $estimated_cost = calculateServiceCost($service_type, $estimated_duration);
                        $stmt = $pdo->prepare("UPDATE job_orders SET estimated_cost = ? WHERE id = ?");
                        $stmt->execute([$estimated_cost, $database_id]);
                    }
                    
                    // Generate receipt automatically
                    try {
                        include_once __DIR__ . '/../backend/job_order_receipt.php';
                        $receipt_generator = new JobOrderReceipt($pdo, $station_id);
                        $receipt_result = $receipt_generator->generateAndSaveReceipt($job_order_id);
                        
                        if ($receipt_result['success']) {
                            $_SESSION['success'] = "Job Order $job_order_id created successfully! Receipt generated automatically.";
                        } else {
                            $_SESSION['success'] = "Job Order $job_order_id created successfully! (Receipt generation failed)";
                        }
                        $_SESSION['last_job_order_id'] = $job_order_id;
                    } catch (Exception $e) {
                        $_SESSION['success'] = "Job Order $job_order_id created successfully!";
                        $_SESSION['last_job_order_id'] = $job_order_id;
                    }

                    // ── Write to audit_logs so Audit Trail report shows this ──
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $detail = "Job Order created: {$job_order_id} | Service: {$service_type} | Customer: {$customer_name}"
                                . " | Vehicle: {$vehicle_plate} | Payment: {$payment_method}"
                                . " | Total: ₱" . number_format((float)($total_amount ?? 0), 2);
                        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                                       VALUES (?, 'transaction', 'Create', ?, 'job_orders', ?, 'Success', ?, ?, NOW())")
                            ->execute([$me['id'], $detail, $database_id, $ip, $ua]);
                    } catch (Exception $e) { /* silent */ }

                    header('Location: joborder.php');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error creating job order: ' . $e->getMessage();
                    header('Location: joborder.php');
                    exit;
                }
                break;
                
            case 'update_status':
                $job_id = $_POST['job_id'] ?? '';
                $new_status = $_POST['new_status'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                // Special check for job order 80
                if ($job_id == 80) {
                    error_log("SPECIAL DEBUG - Job Order 80 Update Requested");
                    error_log("SPECIAL DEBUG - New Status: '$new_status'");
                    
                    // Check if job order 80 exists
                    $existence_check = $pdo->prepare("SELECT id, status, validation_status FROM job_orders WHERE id = ?");
                    $existence_check->execute([$job_id]);
                    $exists = $existence_check->fetch(PDO::FETCH_ASSOC);
                    if ($exists) {
                        error_log("SPECIAL DEBUG - Job Order 80 Exists: YES");
                        error_log("SPECIAL DEBUG - Current Status: '{$exists['status']}', Validation: '{$exists['validation_status']}'");
                    } else {
                        error_log("SPECIAL DEBUG - Job Order 80 Exists: NO");
                    }
                }
                
                try {
                    // Get current job order details
                    $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
                    $stmt->execute([$job_id]);
                    $current = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$current) {
                        $_SESSION['error'] = 'Job order not found.';
                        header('Location: joborder.php');
                        exit;
                    }
                    
                    // Debug: Log current status for troubleshooting
                    error_log("Job Order Status Debug - ID: $job_id, Current Status: '{$current['status']}', New Status: '$new_status', Role: '$role'");
                    
                    // Handle empty status case (should be 'Pending' for approved job orders)
                    $current_status = $current['status'] ?: 'Pending';
                    $validation_status = $current['validation_status'] ?? '';
                    
                    // Staff can update any job order
                    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                    }
                    
                    // Validate status transitions
                    if (!validateStatusTransition($current_status, $new_status, $role)) {
                        $_SESSION['error'] = "Invalid status transition from '$current_status' to '$new_status'. Staff can only update approved job orders.";
                        header('Location: joborder.php');
                        exit;
                    }
                    
                    // Handle completion status with actual duration and cost
                    $update_fields = "status = ?, updated_at = NOW()";
                    $update_values = [$new_status];
                    
                    if ($new_status === 'Completed') {
                        $update_fields .= ", completed_at = NOW()";
                        
                        // If credit payment, update receivables
                        if ($current['payment_method'] === 'Credit' && $current['customer_id']) {
                            $final_cost = calculateServiceCost($current['service_type'], $current['estimated_duration']);
                            $stmt = $pdo->prepare("
                                UPDATE accounts_receivable 
                                SET status = 'Completed', completed_at = NOW()
                                WHERE job_order_id = ? AND status = 'Pending'
                            ");
                            $stmt->execute([$job_id]);
                        }
                    }
                    
                    $update_values[] = $job_id;
                    
                    // Debug: Log the update
                    error_log("Status Update Debug - Job ID: $job_id, New Status: '$new_status', Update Fields: '$update_fields'");
                    error_log("Update Values: " . json_encode($update_values));
                    
                    // Check current status before update
                    $check_stmt = $pdo->prepare("SELECT status, validation_status FROM job_orders WHERE id = ?");
                    $check_stmt->execute([$job_id]);
                    $current_check = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    error_log("Pre-Update Check - Status: '{$current_check['status']}', Validation Status: '{$current_check['validation_status']}'");
                    
                    // Update job order
                    $stmt = $pdo->prepare("UPDATE job_orders SET $update_fields WHERE id = ?");
                    $update_result = $stmt->execute($update_values);
                    error_log("Update Execute Result: " . ($update_result ? 'SUCCESS' : 'FAILED'));
                    error_log("Affected Rows: " . $stmt->rowCount());
                    
                    // If update failed or no rows affected, try direct update
                    if (!$update_result || $stmt->rowCount() == 0) {
                        error_log("Primary update failed, trying direct update method");
                        $direct_stmt = $pdo->prepare("UPDATE job_orders SET status = ?, updated_at = NOW() WHERE id = ?");
                        $direct_result = $direct_stmt->execute([$new_status, $job_id]);
                        error_log("Direct Update Result: " . ($direct_result ? 'SUCCESS' : 'FAILED'));
                        error_log("Direct Update Affected Rows: " . $direct_stmt->rowCount());
                        
                        if ($direct_result && $direct_stmt->rowCount() > 0) {
                            $update_result = true;
                        }
                    }
                    
                    // Verify the update was successful
                    $verify_stmt = $pdo->prepare("SELECT status, validation_status FROM job_orders WHERE id = ?");
                    $verify_stmt->execute([$job_id]);
                    $updated_job = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                    error_log("Post-Update Status - Status: '{$updated_job['status']}', Validation Status: '{$updated_job['validation_status']}'");
                    error_log("Status Comparison - Expected: '$new_status', Actual: '{$updated_job['status']}'");
                    
                    // Special case for job order 80
                    if ($job_id == 80) {
                        error_log("SPECIAL DEBUG - Job Order 80 Status Update");
                        error_log("SPECIAL DEBUG - Before: '{$current_check['status']}', After: '{$updated_job['status']}'");
                        error_log("SPECIAL DEBUG - Update Success: " . ($updated_job['status'] === $new_status ? 'YES' : 'NO'));
                    }
                    
                    // Log status change
                    $stmt = $pdo->prepare("
                        INSERT INTO job_order_audit (
                            job_order_id, action, before_status, after_status,
                            performed_by, performed_at, notes, ip_address, user_agent
                        ) VALUES (?, 'STATUS_CHANGE', ?, ?, ?, NOW(), ?, ?, ?)
                    ");
                    $stmt->execute([
                        $job_id, $current['status'], $new_status, $me['id'], $notes,
                        $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    // Create specific success messages based on the action
                    if ($new_status === 'In Progress') {
                        $_SESSION['success'] = "Job Order #{$current['job_order_id']} successfully set to In Progress.";
                    } elseif ($new_status === 'Completed') {
                        $_SESSION['success'] = "Job Order #{$current['job_order_id']} successfully marked as Completed.";
                    } else {
                        $_SESSION['success'] = "Job Order #{$current['job_order_id']} status updated to $new_status!";
                    }

                    // ── Write to audit_logs ──
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $jo_ref = $current['job_order_id'] ?? "JO-{$job_id}";
                        $old_st = $current['status'] ?? '—';
                        $detail = "Job Order status updated: {$jo_ref} | {$old_st} → {$new_status}"
                                . ($notes ? " | Notes: {$notes}" : '');
                        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                                       VALUES (?, 'transaction', 'Update', ?, 'job_orders', ?, 'Success', ?, ?, NOW())")
                            ->execute([$me['id'], $detail, $job_id, $ip, $ua]);
                    } catch (Exception $e) { /* silent */ }

                    $tracker_tab = $_POST['tracker_tab'] ?? 'approved-validated';
                    header('Location: joborder.php?tracker_tab=' . urlencode($tracker_tab) . '#tracker');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error updating status: ' . $e->getMessage();
                    $tracker_tab = $_POST['tracker_tab'] ?? 'approved-validated';
                    header('Location: joborder.php?tracker_tab=' . urlencode($tracker_tab) . '#tracker');
                    exit;
                }
                break;
                
            case 'validate_job_order':
                $job_id = $_POST['job_id'] ?? '';
                $action = $_POST['validation_action'] ?? ''; // approve or reject
                $notes = $_POST['notes'] ?? '';
                
                try {
                    
                    // Get current job order
                    $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
                    $stmt->execute([$job_id]);
                    $job_order = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$job_order) {
                        $_SESSION['error'] = 'Job order not found.';
                        header('Location: joborder.php');
                        exit;
                    }
                    
                    if ($job_order['validation_status'] !== 'Pending Validation') {
                        $_SESSION['error'] = 'This job order has already been validated.';
                        header('Location: joborder.php');
                        exit;
                    }
                    
                    $new_validation_status = ($action === 'approve') ? 'Approved' : 'Rejected';
                    
                    // Determine job status based on approval
                    if ($action === 'approve') {
                        // Keep status as 'Pending' - Staff will move it to 'In Progress'
                        $new_job_status = 'Pending';
                        
                        // Log approval
                        $stmt = $pdo->prepare("
                            INSERT INTO job_order_audit (
                                job_order_id, action, before_status, after_status,
                                performed_by, performed_at, notes, ip_address, user_agent
                            ) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)
                        ");
                        $stmt->execute([
                            $job_id, $me['id'], 
                            "Job order {$job_order['job_order_id']} approved - ready for staff to start work",
                            $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);
                    } else {
                        $new_job_status = 'Cancelled';
                    }
                    
                    // Update job order
                    $stmt = $pdo->prepare("
                        UPDATE job_orders SET 
                            validation_status = ?, 
                            status = ?,
                            validated_by = ?,
                            validated_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$new_validation_status, $new_job_status, $me['id'], $job_id]);
                    
                    // Log validation action
                    $audit_action = ($action === 'approve') ? 'APPROVE' : 'REJECT';
                    $stmt = $pdo->prepare("
                        INSERT INTO job_order_audit (
                            job_order_id, action, before_status, after_status,
                            performed_by, performed_at, notes, ip_address, user_agent
                        ) VALUES (?, ?, 'Pending Validation', ?, ?, NOW(), ?, ?, ?)
                    ");
                    $stmt->execute([
                        $job_id, $audit_action, $new_validation_status, $me['id'], 
                        "Job order {$job_order['job_order_id']} {$action}d: " . $notes,
                        $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $_SESSION['success'] = "Job Order {$job_order['job_order_id']} {$action}d successfully!";

                    // ── Write to audit_logs ──
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $audit_action_type = ($action === 'approve') ? 'Approve' : 'Reject';
                        $detail = "Job Order {$audit_action_type}d: {$job_order['job_order_id']}"
                                . " | Service: " . ($job_order['service_type'] ?? '—')
                                . " | Customer: " . ($job_order['customer_name'] ?? '—')
                                . ($notes ? " | Notes: {$notes}" : '');
                        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                                       VALUES (?, 'transaction', ?, ?, 'job_orders', ?, 'Success', ?, ?, NOW())")
                            ->execute([$me['id'], $audit_action_type, $detail, $job_id, $ip, $ua]);
                    } catch (Exception $e) { /* silent */ }

                    $tracker_tab = $_POST['tracker_tab'] ?? 'pending-validation';
                    header('Location: joborder.php?tracker_tab=' . urlencode($tracker_tab) . '#tracker');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error validating job order: ' . $e->getMessage();
                    $tracker_tab = $_POST['tracker_tab'] ?? 'pending-validation';
                    header('Location: joborder.php?tracker_tab=' . urlencode($tracker_tab) . '#tracker');
                    exit;
                }
                break;
                
            case 'edit_job_order':
                $job_id = $_POST['job_id'] ?? '';
                
                try {
                    // Get job order details
                    $stmt = $pdo->prepare("
                        SELECT jo.*, m.full_name as mechanic_name, creator.name as created_by_name
                        FROM job_orders jo 
                        LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id 
                        LEFT JOIN users creator ON jo.created_by = creator.id
                        WHERE jo.id = ? AND jo.station_id = ?
                    ");
                    $stmt->execute([$job_id, $station_id]);
                    $job_order = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$job_order) {
                        $_SESSION['error'] = 'Job order not found.';
                        header('Location: joborder.php');
                        exit;
                    }
                    
                    // Store job order details in session for editing
                    $_SESSION['edit_job_order'] = $job_order;
                    
                    // Redirect to encode tab
                    header('Location: joborder.php#encode');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error loading job order for editing: ' . $e->getMessage();
                    header('Location: joborder.php');
                    exit;
                }
                break;
                
            case 'update_job_order':
                $job_order_id = $_POST['job_order_id'] ?? '';
                $customer_name = $_POST['customer_name'] ?? '';
                $credit_customer_id = $_POST['credit_customer_id'] ?? '';
                $service_type = $_POST['service_type'] ?? '';
                $assigned_mechanic_id = $_POST['assigned_mechanic_id'] ?? '';
                $estimated_duration = $_POST['estimated_duration'] ?? '';
                $payment_method = $_POST['payment_method'] ?? '';
                $service_description = $_POST['service_description'] ?? '';
                $vehicle_plate = $_POST['vehicle_plate'] ?? '';
                $vehicle_type = $_POST['vehicle_type'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                // Handle parts data
                $required_parts = $_POST['required_parts'] ?? [];
                $manual_part_names = $_POST['manual_part_names'] ?? [];
                $manual_part_quantities = $_POST['manual_part_quantities'] ?? [];
                $manual_part_costs = $_POST['manual_part_costs'] ?? [];
                
                // Build manual parts array
                $manual_parts = [];
                if (!empty($manual_part_names)) {
                    foreach ($manual_part_names as $index => $name) {
                        if (!empty(trim($name))) {
                            $manual_parts[] = [
                                'part_name' => trim($name),
                                'quantity' => intval($manual_part_quantities[$index] ?? 1),
                                'unit_cost' => floatval($manual_part_costs[$index] ?? 0)
                            ];
                        }
                    }
                }
                
                try {
                    // Validate required fields
                    if (empty($customer_name) || empty($service_type)) {
                        $_SESSION['error'] = 'Customer name and service type are required.';
                        header('Location: joborder.php#encode');
                        exit;
                    }
                    
                    // Update job order
                    $stmt = $pdo->prepare("
                        UPDATE job_orders SET 
                            customer_name = ?, 
                            credit_customer_id = ?, 
                            service_type = ?, 
                            assigned_mechanic_id = ?, 
                            estimated_duration = ?, 
                            payment_method = ?, 
                            service_description = ?, 
                            vehicle_plate = ?, 
                            vehicle_type = ?, 
                            notes = ?, 
                            required_parts = ?, 
                            manual_parts = ?, 
                            status = 'Pending',
                            validation_status = 'Pending Validation',
                            rejected_date = NULL,
                            rejection_remarks = NULL,
                            updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([
                        $customer_name, $credit_customer_id, $service_type, 
                        $assigned_mechanic_id, $estimated_duration, $payment_method, 
                        $service_description, $vehicle_plate, $vehicle_type, 
                        $notes, json_encode($required_parts), json_encode($manual_parts),
                        $job_order_id, $station_id
                    ]);
                    
                    // Log the update with comprehensive field tracking
                    $changes = [];
                    $original_job = $job_order;
                    
                    // Track field changes
                    if ($original_job['customer_name'] !== $customer_name) {
                        $changes[] = "Customer Name: '{$original_job['customer_name']}' → '{$customer_name}'";
                    }
                    if ($original_job['service_type'] !== $service_type) {
                        $changes[] = "Service Type: '{$original_job['service_type']}' → '{$service_type}'";
                    }
                    if ($original_job['assigned_mechanic_id'] !== $assigned_mechanic_id) {
                        $changes[] = "Assigned Mechanic: {$original_job['assigned_mechanic_id']} → {$assigned_mechanic_id}";
                    }
                    if ($original_job['vehicle_plate'] !== $vehicle_plate) {
                        $changes[] = "Vehicle Plate: '{$original_job['vehicle_plate']}' → '{$vehicle_plate}'";
                    }
                    if ($original_job['vehicle_type'] !== $vehicle_type) {
                        $changes[] = "Vehicle Type: '{$original_job['vehicle_type']}' → '{$vehicle_type}'";
                    }
                    if ($original_job['estimated_duration'] != $estimated_duration) {
                        $changes[] = "Estimated Duration: {$original_job['estimated_duration']} → {$estimated_duration}";
                    }
                    if ($original_job['payment_method'] !== $payment_method) {
                        $changes[] = "Payment Method: '{$original_job['payment_method']}' → '{$payment_method}'";
                    }
                    if ($original_job['service_description'] !== $service_description) {
                        $changes[] = "Service Description: Updated";
                    }
                    
                    // Track parts changes
                    $original_parts = json_decode($original_job['required_parts'] ?? '[]', true);
                    $original_manual_parts = json_decode($original_job['manual_parts'] ?? '[]', true);
                    
                    if (json_encode($original_parts) !== json_encode($required_parts)) {
                        $changes[] = "Required Parts: Updated";
                    }
                    if (json_encode($original_manual_parts) !== json_encode($manual_parts)) {
                        $changes[] = "Manual Parts: Updated";
                    }
                    
                    $change_summary = !empty($changes) ? implode('; ', $changes) : "No field changes detected";
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO job_order_audit (
                            job_order_id, action, before_status, after_status,
                            performed_by, performed_at, notes, ip_address, user_agent
                        ) VALUES (?, 'EDIT', 'Rejected', 'Pending', ?, NOW(), ?, ?, ?)
                    ");
                    $stmt->execute([
                        $job_order_id, $me['id'], 
                        "Job order edited and resubmitted for validation. Changes: " . $change_summary,
                        $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    // Clear edit session
                    unset($_SESSION['edit_job_order']);

                    // ── Write to audit_logs ──
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $detail = "Job Order edited: #{$job_order_id} | Changes: {$change_summary}";
                        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                                       VALUES (?, 'transaction', 'Update', ?, 'job_orders', ?, 'Success', ?, ?, NOW())")
                            ->execute([$me['id'], $detail, $job_id, $ip, $ua]);
                    } catch (Exception $e) { /* silent */ }

                    $_SESSION['success'] = "Job Order #{$job_order_id} updated successfully!";
                    header('Location: joborder.php#tracker');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error updating job order: ' . $e->getMessage();
                    header('Location: joborder.php#encode');
                    exit;
                }
                break;
        }
    }
}

// Fetch data for forms
$mechanics = [];
$customers = [];
$job_orders = [];
$service_types = [];
$payment_methods = [];

try {
        // Create audit_log table if it doesn't exist (for manual override logging)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(100) NOT NULL,
                user_id INT,
                station_id INT,
                details TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45),
                INDEX idx_action (action),
                INDEX idx_user_id (user_id),
                INDEX idx_station_id (station_id),
                INDEX idx_created_at (created_at)
            )
        ");
        
        // Create service types table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_order_service_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                service_key VARCHAR(50) UNIQUE NOT NULL,
                service_name VARCHAR(100) NOT NULL,
                base_rate_per_hour DECIMAL(10,2) DEFAULT 0.00,
                icon_class VARCHAR(50),
                color_class VARCHAR(20),
                allows_custom_input BOOLEAN DEFAULT FALSE,
                active BOOLEAN DEFAULT TRUE,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_service_key (service_key),
                INDEX idx_active (active),
                INDEX idx_sort_order (sort_order)
            )
        ");
    
    // Load service types from database (no hardcoded values)
    try {
        $stmt = $pdo->query("
            SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, allows_custom_input 
            FROM job_order_service_types 
            WHERE active = TRUE 
            ORDER BY sort_order, service_name
        ");
        $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no service types exist, create default ones
        if (empty($service_types)) {
            $default_services = [
                ['oil_change', 'Oil Change', 350.00, 'fas fa-oil-can', 'text-success', 1],
                ['tire_repair', 'Tire Repair', 300.00, 'fas fa-circle-dot', 'text-warning', 2],
                ['calibration', 'Calibration', 500.00, 'fas fa-gauge-high', 'text-info', 3],
                ['general_maintenance', 'General Maintenance', 400.00, 'fas fa-wrench', 'text-primary', 4],
                ['engine_repair', 'Engine Repair', 600.00, 'fas fa-engine-warning', 'text-danger', 5],
                ['brake_service', 'Brake Service', 450.00, 'fas fa-circle-stop', 'text-dark', 6],
                ['electrical', 'Electrical', 550.00, 'fas fa-bolt', 'text-warning', 7],
                ['air_conditioning', 'Air Conditioning', 500.00, 'fas fa-snowflake', 'text-info', 8],
                ['transmission_service', 'Transmission Service', 650.00, 'fas fa-cogs', 'text-danger', 9],
                ['suspension_repair', 'Suspension Repair', 480.00, 'fas fa-car-side', 'text-secondary', 10],
                ['wheel_alignment', 'Wheel Alignment', 350.00, 'fas fa-arrows-left-right', 'text-primary', 11],
                ['battery_replacement', 'Battery Replacement', 250.00, 'fas fa-car-battery', 'text-success', 12],
                ['diagnostic_check', 'Diagnostic Check', 400.00, 'fas fa-stethoscope', 'text-info', 13],
                ['detailing_cleaning', 'Detailing / Cleaning', 300.00, 'fas fa-spray-can-sparkles', 'text-primary', 14],
                ['other', 'Other (manual input for non-listed services)', 400.00, 'fas fa-plus', 'text-secondary', 15]
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO job_order_service_types 
                (service_key, service_name, base_rate_per_hour, icon_class, color_class, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($default_services as $service) {
                $stmt->execute($service);
            }
            
            // Update the 'other' service type to allow custom input
            $pdo->exec("UPDATE job_order_service_types SET allows_custom_input = TRUE WHERE service_key = 'other'");
            
            // Reload service types
            $stmt = $pdo->query("
                SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, allows_custom_input 
                FROM job_order_service_types 
                WHERE active = TRUE 
                ORDER BY sort_order, service_name
            ");
            $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Fallback if database fails
        $service_types = [];
    }
    
    // Load payment methods from database (no hardcoded values)
    try {
        // Create payment methods table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_method_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                method_key VARCHAR(50) NOT NULL UNIQUE,
                method_name VARCHAR(100) NOT NULL,
                icon_class VARCHAR(100) NULL,
                color_class VARCHAR(100) NULL,
                sort_order INT DEFAULT 0,
                active BOOLEAN DEFAULT TRUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        $stmt = $pdo->query("
            SELECT method_key, method_name 
            FROM payment_method_config 
            WHERE active = TRUE 
            ORDER BY sort_order, method_name
        ");
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no payment methods exist, create default ones
        if (empty($payment_methods)) {
            $default_payments = [
                ['cash', 'Cash', 'fas fa-money-bill-wave', 'text-success', 1],
                ['card', 'Card', 'fas fa-credit-card', 'text-primary', 2],
                ['ewallet', 'E-Wallet', 'fas fa-mobile-alt', 'text-info', 3],
                ['efuel', 'E-Fuel Card', 'fas fa-gas-pump', 'text-danger', 4],
                ['credit', 'Credit', 'fas fa-hand-holding-usd', 'text-warning', 5]
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO payment_method_config 
                (method_key, method_name, icon_class, color_class, sort_order) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($default_payments as $payment) {
                $stmt->execute($payment);
            }
            
            // Reload payment methods
            $stmt = $pdo->query("
                SELECT method_key, method_name 
                FROM payment_method_config 
                WHERE active = TRUE 
                ORDER BY sort_order, method_name
            ");
            $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Fallback if database fails
        $payment_methods = [];
    }
    
    // Load mechanics from database (FORCE CORRECT MECHANICS ONLY)
    try {
        // Force drop and recreate mechanics table to ensure correct data
        $pdo->exec("DROP TABLE IF EXISTS mechanics");
        
        $sql = "CREATE TABLE mechanics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            specialization VARCHAR(100) DEFAULT 'General Mechanic',
            status ENUM('active', 'inactive') DEFAULT 'active',
            station_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_status (status),
            INDEX idx_station_id (station_id),
            INDEX idx_full_name (full_name)
        )";
        
        $pdo->exec($sql);
        
        // Insert ONLY the correct Petron mechanics
        $correct_mechanics = [
            ['CABUSOG, LOLOY', 'General Mechanic'],
            ['ESLIT, EDGAR', 'General Mechanic'],
            ['ESLIT, MARK', 'General Mechanic'],
            ['PIQUERO, CHRIS', 'General Mechanic'],
            ['EBUÑA, TATA', 'General Mechanic'],
            ['SOLAMIN, JEFFERSON', 'General Mechanic'],
            ['BELARMINO, CARLOS MIGUEL', 'General Mechanic'],
            ['AGUADA, JONARD', 'General Mechanic'],
            ['PAROHINGOG, DANNY', 'General Mechanic'],
            ['BUGAY, LIEBERT', 'General Mechanic'],
            ['CASTILLO, MARJUN', 'General Mechanic'],
            ['WENNIBER, SALACOB', 'General Mechanic'],
            ['JELISTER, LARAGA', 'General Mechanic']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO mechanics (full_name, specialization) VALUES (?, ?)");
        
        foreach ($correct_mechanics as $mechanic) {
            $stmt->execute($mechanic);
        }
        
        // Load the correct mechanics
        $stmt = $pdo->query("
            SELECT id, full_name, specialization 
            FROM mechanics 
            WHERE status = 'Active' 
            ORDER BY full_name
        ");
        $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback if database fails
        $mechanics = [];
    }
    
    // Get credit customers from receivables module (auto-pull from registered credit accounts)
    try {
        // Check if accounts_receivable table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'accounts_receivable'");
        if ($stmt->rowCount() > 0) {
            // Pull from receivables module
            $stmt = $pdo->prepare("
                SELECT ar.id, ar.customer_id, ar.reference_number, ar.amount, ar.due_date, ar.status,
                       c.name as customer_name, c.credit_limit, c.balance
                FROM accounts_receivable ar
                LEFT JOIN customers c ON ar.customer_id = c.id
                WHERE ar.station_id = ? AND ar.status IN ('Pending', 'Active') 
                ORDER BY c.name
            ");
            $stmt->execute([$station_id]);
            $credit_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format for dropdown display
            $customers = [];
            foreach ($credit_customers as $customer) {
                $customers[] = [
                    'id' => $customer['customer_id'],
                    'name' => $customer['customer_name'],
                    'credit_limit' => $customer['credit_limit'],
                    'balance' => $customer['balance'],
                    'reference_number' => $customer['reference_number'],
                    'receivable_id' => $customer['id']
                ];
            }
        } else {
            // Fallback to customers table if receivables module doesn't exist
            $stmt = $pdo->prepare("SELECT id, name, credit_limit, balance FROM customers WHERE station_id = ? ORDER BY name");
            $stmt->execute([$station_id]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Fallback if database fails
        $customers = [];
    }
    
    // Get job orders based on role
    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // Staff can see all job orders at their station for tracker visibility
        $stmt = $pdo->prepare("
            SELECT jo.*, m.full_name as mechanic_name, creator.name as created_by_name
            FROM job_orders jo 
            LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id 
            LEFT JOIN users creator ON jo.created_by = creator.id
            WHERE jo.station_id = ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id]);
    } else {
        // Admin/SuperAdmin can see all job orders across all stations
        $stmt = $pdo->prepare("
            SELECT jo.*, m.full_name as mechanic_name, creator.name as created_by_name, s.location as station_location
            FROM job_orders jo 
            LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id 
            LEFT JOIN users creator ON jo.created_by = creator.id
            LEFT JOIN stations s ON jo.station_id = s.id
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute();
    }
    $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log job order counts for verification
    error_log("Job Orders Debug - Total: " . count($job_orders) . " | Role: $role | Station: $station_id");
    
    $approved_count = count(array_filter($job_orders, fn($j) => $j['validation_status'] === 'Approved'));
    $pending_count = count(array_filter($job_orders, fn($j) => $j['validation_status'] === 'Pending Validation'));
    $in_progress_count = count(array_filter($job_orders, fn($j) => $j['status'] === 'In Progress'));
    $completed_count = count(array_filter($job_orders, fn($j) => $j['status'] === 'Completed'));
    $rejected_count = count(array_filter($job_orders, fn($j) => $j['status'] === 'Rejected'));
    
    error_log("Job Orders Status Counts - Approved: $approved_count, Pending: $pending_count, In-Progress: $in_progress_count, Completed: $completed_count, Rejected: $rejected_count");
    
} catch (Exception $e) {
    // Handle errors gracefully
    error_log("Error fetching job order data: " . $e->getMessage());
    $job_orders = [];
}

include __DIR__ . '/../partials/header.php';
?>

<!-- Required Parts Modal -->
<div id="requiredPartsModal" class="modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div class="modal-content" style="background-color: white; margin: auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 900px; max-height: 85vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.28);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #003d7a;">
            <h3 style="margin: 0; color: #003d7a;">
                <i class="fas fa-tools"></i> Required Parts for <span id="modalServiceName"></span>
            </h3>
            <button type="button" onclick="closeRequiredPartsModal()" style="background: #dc3545; color: white; border: none; border-radius: 4px; padding: 8px 12px; cursor: pointer;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        <div id="requiredPartsContent">
            <div style="text-align: center; padding: 40px; color: #666;">
                <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                <p>Loading required parts...</p>
            </div>
        </div>
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 13px; color: #666;">
                <i class="fas fa-info-circle"></i> Select parts and click "Add to Job Order" to include them.
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="selectAllModalParts()" style="background: #6c757d; color: white; border: none; border-radius: 4px; padding: 10px 20px; cursor: pointer;">
                    <i class="fas fa-check-double"></i> Select All
                </button>
                <button type="button" onclick="applyModalPartsToJobOrder()" style="background: #28a745; color: white; border: none; border-radius: 4px; padding: 10px 20px; cursor: pointer; font-weight: bold;">
                    <i class="fas fa-plus"></i> Add to Job Order
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" class="toast-container"></div>

<style>
.job-orders-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.job-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 30px;
    border-bottom: 2px solid #e9ecef;
}

.tab-btn {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: #666;
    transition: all 0.3s ease;
}

.tab-btn:hover {
    color: #003d7a;
}

.tab-btn.active {
    color: #003d7a;
    border-bottom-color: #003d7a;
}

.tab-content {
    display: none;
    opacity: 0;
}

.tab-content.active {
    display: block;
    animation: tabFadeIn 0.22s ease forwards;
}

@keyframes tabFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.job-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    padding: 30px;
    margin-bottom: 20px;
}

.tracker-tabs {
    display: flex;
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 20px;
    gap: 5px;
}

.tracker-tab-btn {
    background: #f8f9fa;
    border: none;
    padding: 12px 20px;
    cursor: pointer;
    border-radius: 8px 8px 0 0;
    font-weight: 500;
    color: #6c757d;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}

.tracker-tab-btn:hover {
    background: #e9ecef;
    color: #495057;
}

.tracker-tab-btn.active {
    background: #003d7a;
    color: white;
    border-bottom: 2px solid #003d7a;
}

.tracker-tab-btn .badge {
    background: rgba(255, 255, 255, 0.2);
    color: inherit;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}

.tracker-tab-btn.active .badge {
    background: rgba(255, 255, 255, 0.3);
}

.tracker-tab-content {
    display: none;
}

.tracker-tab-content.active {
    display: block;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.status-in-progress {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.status-completed {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-approved-validated {
    background: #cce5ff;
    color: #004085;
    border: 1px solid #99d6ff;
}

.status-rejected {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Toast Notification System */
.toast-container {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: center;
}

.toast {
    min-width: 300px;
    padding: 16px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    opacity: 0;
    transform: translateX(100%);
    transition: all 0.3s ease-in-out;
    border-left: 4px solid;
}

.toast.show {
    opacity: 1;
    transform: translateX(0);
}

.toast.hide {
    opacity: 0;
    transform: translateX(100%);
}

.toast-success {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color: #155724;
    border-left-color: #28a745;
}

.toast-success .toast-icon {
    color: #28a745;
    font-size: 18px;
}

.toast-info {
    background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
    color: #0c5460;
    border-left-color: #17a2b8;
}

.toast-info .toast-icon {
    color: #17a2b8;
    font-size: 18px;
}

.toast-message {
    flex: 1;
    line-height: 1.4;
}

.toast-close {
    background: none;
    border: none;
    color: inherit;
    opacity: 0.7;
    cursor: pointer;
    font-size: 16px;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: opacity 0.2s;
}

.toast-close:hover {
    opacity: 1;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* Alert Messages */
.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-weight: 500;
    border-left: 4px solid;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color: #155724;
    border-left-color: #28a745;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.15);
}

.alert-success::before {
    content: "";
    background: #28a745;
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 12px;
}

.alert-error {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    color: #721c24;
    border-left-color: #dc3545;
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.15);
}

.alert-error::before {
    content: "";
    background: #dc3545;
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 12px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #003d7a;
    box-shadow: 0 0 0 3px rgba(0,61,122,0.1);
}

.btn-primary {
    background: #003d7a;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.3s ease;
}

.btn-primary:hover {
    background: #002855;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-in-progress {
    background: #fff3cd;
    color: #856404;
}

.status-completed {
    background: #d4edda;
    color: #155724;
}

.job-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.job-table th,
.job-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.job-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.job-table tr:hover {
    background: #f8f9fa;
}

/* Force all dropdowns to open downward */
.form-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 16px;
    padding-right: 32px !important;
}

/* Ensure dropdown opens downward */
.form-select:focus {
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 16px;
}

/* Override any browser default dropdown behavior */
select.form-select {
    direction: ltr;
}

/* Ensure dropdown list appears below the select */
select.form-select option {
    direction: ltr;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .job-tabs {
        flex-direction: column;
    }
}
</style>

<div class="job-orders-container">
    <div class="page-head">
        <div>
            <h1 class="h1">Job Orders</h1>
            <div class="sub" id="page-subtitle">Encode, track, and manage vehicle service orders</div>
        </div>
    </div>

<?php
$last_jo_id = $_SESSION['last_job_order_id'] ?? null;
unset($_SESSION['last_job_order_id']);
?>
<?php if($msg): ?>
<div class="alert <?php echo strpos($msg, 'Error') !== false ? 'alert-error' : 'alert-success'; ?>" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
    <span><?php echo $msg; ?></span>
    <?php if ($last_jo_id && strpos($msg, 'created successfully') !== false): ?>
    <button onclick="viewJobOrderReceipt('<?php echo htmlspecialchars($last_jo_id); ?>')"
            style="padding:7px 16px; background:#003d7a; color:#fff; border:none; border-radius:5px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0;">
        <i class="fas fa-print"></i> Print Receipt
    </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($last_jo_id && strpos($msg ?? '', 'created successfully') !== false): ?>
<script>
// Auto-open receipt popup immediately after job order creation
window.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        viewJobOrderReceipt('<?php echo htmlspecialchars($last_jo_id); ?>');
    }, 400);
});
</script>
<?php endif; ?>

    <div class="job-tabs" <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>style="display:none;"<?php endif; ?>>
        <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
            <!-- Staff: tabs hidden — navigation driven by sidebar sub-menu links -->
            <button class="tab-btn active" onclick="switchTab('encode')">
                <i class="fas fa-plus"></i> Encode Job Order
            </button>
            <button class="tab-btn" onclick="switchTab('tracker')">
                <i class="fas fa-tasks"></i> Job Order Status Tracker
            </button>
        <?php else: ?>
            <!-- Admin/SuperAdmin: Transparency and Reports -->
            <button class="tab-btn active" onclick="switchTab('transparency')">
                <i class="fas fa-eye"></i> Transparency Tab
            </button>
        <?php endif; ?>
    </div>

    <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
    <!-- Encode Job Order Tab (Staff Only) -->
    <div id="encode-tab" class="tab-content active">
        <div class="job-card">
            <?php 
            $is_editing = isset($_SESSION['edit_job_order']);
            $edit_job = $_SESSION['edit_job_order'] ?? null;
            ?>
            <h2 style="margin-bottom: 30px; color: #003d7a;">
                <i class="fas fa-<?php echo $is_editing ? 'edit' : 'wrench'; ?>"></i> 
                <?php echo $is_editing ? 'Edit Job Order' : 'Encode Job Order'; ?>
                <?php if ($is_editing): ?>
                    <span style="color: #dc3545; font-size: 14px; margin-left: 10px;">
                        (Job Order #<?php echo $edit_job['id']; ?>)
                    </span>
                <?php endif; ?>
            </h2>
            
            <?php if ($is_editing): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
                    <h5 style="color: #856404; margin: 0 0 10px 0;">
                        <i class="fas fa-exclamation-triangle"></i> Editing Rejected Job Order
                    </h5>
                    <p style="margin: 0; color: #856404; font-size: 14px;">
                        Please correct the errors that caused this job order to be rejected. 
                        After saving, the job order will return to Pending Validation for manager review.
                    </p>
                </div>
                
                <script>
                // Load existing job order data when editing
                document.addEventListener('DOMContentLoaded', function() {
                    <?php if ($is_editing && !empty($edit_job)): ?>
                        // Load existing parts data
                        const existingParts = <?php echo json_encode($edit_job['required_parts'] ?? []); ?>;
                        const existingManualParts = <?php echo json_encode($edit_job['manual_parts'] ?? []); ?>;
                        
                        console.log('Loading existing parts for editing:', existingParts, existingManualParts);
                        
                        // Load existing required parts
                        if (existingParts && existingParts.length > 0) {
                            setTimeout(() => {
                                loadExistingParts(existingParts);
                            }, 500);
                        }
                        
                        // Load existing manual parts
                        if (existingManualParts && existingManualParts.length > 0) {
                            setTimeout(() => {
                                loadExistingManualParts(existingManualParts);
                            }, 600);
                        }
                    <?php endif; ?>
                });
                
                function loadExistingParts(parts) {
                    const container = document.getElementById('required-parts-container');
                    if (!container) return;
                    
                    // Clear existing content
                    container.innerHTML = '';
                    
                    parts.forEach(part => {
                        const partDiv = document.createElement('div');
                        partDiv.style.cssText = 'margin: 5px 0; display: flex; align-items: center;';
                        partDiv.innerHTML = `
                            <input type="checkbox" name="required_parts[]" value="${part.part_name}" 
                                   data-part-id="${part.part_id}" data-category="${part.category}" 
                                   data-unit-cost="${part.unit_cost}" checked onchange="updatePartsSummary()">
                            <label style="margin-left: 8px; flex: 1;">
                                ${part.part_name} (${part.category}) - ₱${parseFloat(part.unit_cost).toFixed(2)}
                            </label>
                        `;
                        container.appendChild(partDiv);
                    });
                    
                    updatePartsSummary();
                }
                
                function loadExistingManualParts(manualParts) {
                    const container = document.getElementById('manual-parts-list');
                    if (!container) return;
                    
                    // Clear existing content
                    container.innerHTML = '';
                    
                    manualParts.forEach((part, index) => {
                        addManualPartRowWithData(part);
                    });
                    
                    updatePartsSummary();
                }
                
                function addManualPartRowWithData(part) {
                    const container = document.getElementById('manual-parts-list');
                    const rowDiv = document.createElement('div');
                    rowDiv.style.cssText = 'margin: 5px 0; display: flex; align-items: center; gap: 10px;';
                    rowDiv.innerHTML = `
                        <input type="text" name="manual_part_names[]" placeholder="Part name" 
                               value="${part.part_name || ''}" style="flex: 1;" onchange="updatePartsSummary()">
                        <input type="number" name="manual_part_quantities[]" placeholder="Qty" 
                               value="${part.quantity || 1}" min="1" style="width: 80px;" onchange="updatePartsSummary()">
                        <input type="number" name="manual_part_costs[]" placeholder="Cost" 
                               value="${part.unit_cost || 0}" min="0" step="0.01" style="width: 100px;" onchange="updatePartsSummary()">
                        <button type="button" onclick="this.parentElement.remove(); updatePartsSummary();" 
                                class="btn btn-sm btn-danger" style="padding: 2px 8px;">×</button>
                    `;
                    container.appendChild(rowDiv);
                }
                </script>
            <?php endif; ?>
            
            <form method="post" action="joborder.php" onsubmit="injectPartsServiceData()">
                <input type="hidden" name="action" value="<?php echo $is_editing ? 'update_job_order' : 'create_job_order'; ?>">
                <?php if ($is_editing): ?>
                    <input type="hidden" name="job_order_id" value="<?php echo $edit_job['id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-input" 
                               placeholder="Walk-in customer name" required
                               value="<?php echo htmlspecialchars($edit_job['customer_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Credit Customer (Optional)</label>
                        <select name="credit_customer_id" class="form-select" id="credit_customer_select" onchange="handleCreditCustomerChange()">
                            <option value="">Select credit customer (optional)</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" 
                                        data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        data-credit-limit="<?php echo $customer['credit_limit']; ?>"
                                        data-balance="<?php echo $customer['balance']; ?>"
                                        data-reference-number="<?php echo htmlspecialchars($customer['reference_number'] ?? ''); ?>"
                                        data-receivable-id="<?php echo $customer['receivable_id'] ?? ''; ?>"
                                        <?php echo ($edit_job['credit_customer_id'] ?? '') == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['id'] . ' - ' . $customer['name']); ?>
                                    (Limit: <?php echo number_format($customer['credit_limit'], 2); ?>, Balance: <?php echo number_format($customer['balance'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="receivable_id" id="receivable_id" value="">
                        <input type="hidden" name="is_credit_transaction" id="is_credit_transaction" value="false">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Service Type</label>
                        <div id="service-types-container" style="border: 1px solid #ddd; padding: 15px; border-radius: 6px 6px 0 0; background: #f8f9fa; max-height: 300px; overflow-y: auto;">
                            <?php
                            // Load service types with pricing information
                            try {
                                // Use simple query that definitely works
                                $service_types_with_parts = [];
                                
                                // First check if table exists
                                $table_check = $pdo->query("SHOW TABLES LIKE 'job_order_service_types'");
                                if ($table_check->rowCount() > 0) {
                                    // Check if service_price column exists
                                    $column_check = $pdo->query("SHOW COLUMNS FROM job_order_service_types LIKE 'service_price'");
                                    if ($column_check->rowCount() > 0) {
                                        // Use the working query
                                        $stmt = $pdo->query("SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, 0 as parts_count FROM job_order_service_types WHERE active = TRUE ORDER BY sort_order, service_name");
                                        $service_types_with_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    } else {
                                        // Fallback: Add service_price column if missing
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN service_price DECIMAL(10,2) NOT NULL DEFAULT 400.00");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN min_price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN max_price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN price_description VARCHAR(255) NULL");
                                        $pdo->exec("ALTER TABLE job_order_service_types ADD COLUMN pricing_notes TEXT NULL");
                                        
                                        // Try query again
                                        $stmt = $pdo->query("SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, 0 as parts_count FROM job_order_service_types WHERE active = TRUE ORDER BY sort_order, service_name");
                                        $service_types_with_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    }
                                } else {
                                    // Create table if it doesn't exist
                                    $pdo->exec("CREATE TABLE IF NOT EXISTS job_order_service_types (
                                        id INT AUTO_INCREMENT PRIMARY KEY,
                                        service_key VARCHAR(50) UNIQUE NOT NULL,
                                        service_name VARCHAR(100) NOT NULL,
                                        base_rate_per_hour DECIMAL(10,2) DEFAULT 0.00,
                                        service_price DECIMAL(10,2) NOT NULL DEFAULT 400.00,
                                        min_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        max_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        price_description VARCHAR(255) NULL,
                                        pricing_notes TEXT NULL,
                                        icon_class VARCHAR(50) NULL,
                                        color_class VARCHAR(20) NULL,
                                        allows_custom_input TINYINT(1) DEFAULT 0,
                                        active TINYINT(1) DEFAULT 1,
                                        sort_order INT DEFAULT 0,
                                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                                    )");
                                    
                                    // Insert default data
                                    $default_services = [
                                        ['oil_change', 'Oil Change', 3540.00, 1700.00, 5380.00, '₱1,700 to ₱5,380 (depends on oil type and filter)', 'Consider: Oil type (mineral vs synthetic), filter quality, engine size, oil capacity', 'fas fa-oil-can', 'text-success', 1],
                                        ['tire_repair', 'Tire Repair', 500.00, 300.00, 700.00, '₱300 to ₱700 per tire (depends on puncture size)', 'Consider: Puncture size/location, tire condition, patch vs plug vs replacement', 'fas fa-circle-dot', 'text-warning', 2],
                                        ['calibration', 'Calibration', 3400.00, 800.00, 6000.00, '₱800 to ₱6,000+ (depends on equipment type)', 'Consider: Equipment type, number of pumps, calibration complexity', 'fas fa-tachometer-alt', 'text-info', 3],
                                    ];
                                    
                                    $stmt = $pdo->prepare("INSERT INTO job_order_service_types (service_key, service_name, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, allows_custom_input, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    foreach ($default_services as $service) {
                                        $stmt->execute($service);
                                    }
                                    
                                    // Get the data
                                    $stmt = $pdo->query("SELECT service_key, service_name, base_rate_per_hour, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, 0 as parts_count FROM job_order_service_types WHERE active = TRUE ORDER BY sort_order, service_name");
                                    $service_types_with_parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                }
                                
                                // Get selected service types for editing
                                $selected_services = [];
                                if ($is_editing && !empty($edit_job['service_type'])) {
                                    $selected_services = array_map('trim', explode(',', $edit_job['service_type']));
                                }
                                
                                foreach ($service_types_with_parts as $service):
                                    $is_selected = in_array($service['service_name'], $selected_services);
                                ?>
                                    <div style="margin: 8px 0; padding: 12px; background: white; border-radius: 4px; border: 1px solid #e9ecef;">
                                        <div style="display: flex; align-items: center; margin-bottom: 12px; padding: 8px; border-radius: 6px; background: #f8f9fa; border: 1px solid #e9ecef; transition: all 0.2s ease;" 
                                             onmouseover="this.style.background='#e9ecef'; this.style.borderColor='#dee2e6';"
                                             onmouseout="this.style.background='#f8f9fa'; this.style.borderColor='#e9ecef';">
                                            <input type="checkbox" 
                                                   name="service_types[]" 
                                                   value="<?php echo htmlspecialchars($service['service_name']); ?>"
                                                   id="service_<?php echo htmlspecialchars($service['service_key']); ?>"
                                                   data-service-key="<?php echo htmlspecialchars($service['service_key']); ?>"
                                                   data-min-price="<?php echo htmlspecialchars($service['min_price']); ?>"
                                                   data-max-price="<?php echo htmlspecialchars($service['max_price']); ?>"
                                                   data-default-price="<?php echo htmlspecialchars($service['service_price']); ?>"
                                                   data-parts-count="<?php echo $service['parts_count']; ?>"
                                                   onchange="toggleServicePrice('<?php echo htmlspecialchars($service['service_key']); ?>')"
                                                   <?php echo $is_selected ? 'checked' : ''; ?>
                                                   style="margin-right: 12px; width: 18px; height: 18px; cursor: pointer;">
                                            <label for="service_<?php echo htmlspecialchars($service['service_key']); ?>" style="flex: 1; margin: 0; cursor: pointer; display: flex; align-items: center; font-size: 0.95em;">
                                                <?php if (!empty($service['icon_class'])): ?>
                                                    <i class="<?php echo htmlspecialchars($service['icon_class']); ?>" style="margin-right: 8px; color: <?php echo htmlspecialchars($service['color_class'] ?? '#003d7a'); ?>; font-size: 1.1em;"></i>
                                                <?php endif; ?>
                                                <span style="font-weight: 600; color: #212529;"><?php echo htmlspecialchars($service['service_name']); ?></span>
                                                <span style="margin-left: 8px; font-size: 0.8em; color: #6c757d; font-weight: normal;">
                                                    (₱<?php echo number_format($service['min_price'], 0); ?> - ₱<?php echo number_format($service['max_price'], 0); ?>)
                                                </span>
                                            </label>
                                        </div>
                                        
                                        <!-- Price Range Display and Edit -->
                                        <div id="price_container_<?php echo htmlspecialchars($service['service_key']); ?>" style="margin-left: 30px; display: <?php echo $is_selected ? 'block' : 'none'; ?>;">
                                            <div style="background: #f8f9fa; padding: 8px; border-radius: 5px; border-left: 4px solid #007bff; margin-bottom: 8px;">
                                                <div style="font-size: 0.9em; color: #495057; margin-bottom: 4px; font-weight: 600;">
                                                    <i class="fas fa-tag" style="color: #007bff; margin-right: 5px;"></i>
                                                    Price Range:
                                                </div>
                                                <div style="font-size: 1.1em; font-weight: bold; color: #212529; margin-bottom: 4px;">
                                                    ₱<?php echo number_format($service['min_price'], 2); ?> - ₱<?php echo number_format($service['max_price'], 2); ?>
                                                </div>
                                                <?php if (!empty($service['price_description'])): ?>
                                                    <div style="font-size: 0.8em; color: #6c757d; font-style: italic;">
                                                        <?php echo htmlspecialchars($service['price_description']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                                <label style="font-size: 0.9em; margin: 0; font-weight: 500; color: #495057;">
                                                    <i class="fas fa-edit" style="color: #28a745; margin-right: 3px;"></i>
                                                    Your Price:
                                                </label>
                                                <div style="display: flex; align-items: center; border: 1px solid #ced4da; border-radius: 4px; overflow: hidden;">
                                                    <span style="background: #e9ecef; padding: 6px 8px; font-size: 0.9em; color: #495057; font-weight: 500; border-right: 1px solid #ced4da;">₱</span>
                                                    <input type="number" 
                                                           name="service_price_<?php echo htmlspecialchars($service['service_key']); ?>"
                                                           id="price_<?php echo htmlspecialchars($service['service_key']); ?>"
                                                           min="<?php echo htmlspecialchars($service['min_price']); ?>"
                                                           max="<?php echo htmlspecialchars($service['max_price']); ?>"
                                                           step="0.01"
                                                           value="<?php echo htmlspecialchars($service['service_price']); ?>"
                                                           onchange="validateServicePrice('<?php echo htmlspecialchars($service['service_key']); ?>')"
                                                           onkeyup="updatePaymentSummary()"
                                                           style="width: 130px; padding: 6px 8px; border: none; font-size: 0.95em; font-weight: 500;"
                                                           placeholder="0.00">
                                                </div>
                                                <span id="price_error_<?php echo htmlspecialchars($service['service_key']); ?>" style="font-size: 0.8em; color: #dc3545; display: none; margin-left: 5px;"></span>
                                            </div>
                                            <?php if (!empty($service['pricing_notes'])): ?>
                                                <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 6px 8px; margin-top: 5px;">
                                                    <div style="font-size: 0.8em; color: #856404; font-weight: 500; margin-bottom: 2px;">
                                                        <i class="fas fa-lightbulb" style="color: #ffc107; margin-right: 3px;"></i>
                                                        Considerations:
                                                    </div>
                                                    <div style="font-size: 0.75em; color: #856404; line-height: 1.3;">
                                                        <?php echo htmlspecialchars($service['pricing_notes']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>


                                        </div>
                                    </div>
                                <?php endforeach;
                            } catch (Exception $e) {
                                echo '<div style="color: red; padding: 10px;">Error loading service types: ' . htmlspecialchars($e->getMessage()) . '</div>';
                            }
                            ?>
                        </div>
                        
                        <div class="section-action-bar">
                            <button type="button" onclick="selectAllServiceTypes()" class="action-btn action-btn--primary">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" onclick="clearServiceTypes()" class="action-btn action-btn--outline-danger">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>

                        <!-- Hidden input for backward compatibility -->
                        <input type="hidden" name="service_type" id="service_type_combined" value="">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Assigned Mechanic</label>
                        <select name="assigned_mechanic_id" class="form-select" required id="mechanic_select" onchange="checkMechanicStatus()">
                            <option value="">Select mechanic</option>
                            <?php foreach ($mechanics as $mechanic): ?>
                                <option value="<?php echo $mechanic['id']; ?>" 
                                        <?php echo ($edit_job['assigned_mechanic_id'] ?? '') == $mechanic['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mechanic['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="staff_override" id="staff_override" value="false">
                        <input type="hidden" name="override_reason" id="override_reason" value="">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Vehicle Plate Number</label>
                        <input type="text" name="vehicle_plate" class="form-input" 
                               placeholder="Enter plate number" required
                               value="<?php echo htmlspecialchars($edit_job['vehicle_plate'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Vehicle Type</label>
                        <input type="text" name="vehicle_type" class="form-input" 
                               placeholder="Enter vehicle description (e.g., Toyota Hilux Pickup, Honda Click 125)" required
                               value="<?php echo htmlspecialchars($edit_job['vehicle_type'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div id="auto-populate-indicator" style="margin-top: 5px; font-size: 12px; color: #28a745; display: block;">
                            <i class="fas fa-info-circle"></i> <span id="auto-populate-text">Select service types above to auto-populate parts</span>
                        </div>
                        <div id="required-parts-container">
                    <style>
                        .part-item {
                            margin: 6px 0;
                            padding: 9px 14px;
                            background: #fff;
                            border: 1px solid #e2e8f0;
                            border-radius: 6px;
                            display: flex;
                            align-items: center;
                            transition: border-color 0.15s, box-shadow 0.15s;
                        }
                        .part-item:hover {
                            border-color: #003d7a;
                            box-shadow: 0 1px 4px rgba(0,61,122,0.10);
                        }
                        .part-item input[type="checkbox"] {
                            accent-color: #003d7a;
                            width: 16px;
                            height: 16px;
                            cursor: pointer;
                            flex-shrink: 0;
                        }
                        .part-item label {
                            cursor: pointer;
                            flex: 1;
                            margin: 0 0 0 10px;
                            font-size: 13px;
                            font-weight: 500;
                            color: #212529;
                            display: flex;
                            align-items: center;
                            flex-wrap: wrap;
                            gap: 4px;
                        }
                        .part-item .part-controls {
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            flex-shrink: 0;
                        }
                        .part-item .part-controls input[type="number"] {
                            width: 58px;
                            padding: 5px 6px;
                            border: 1px solid #ced4da;
                            border-radius: 4px;
                            font-size: 13px;
                            text-align: center;
                        }
                        .part-item .part-controls input[type="text"] {
                            width: 130px;
                            padding: 5px 8px;
                            border: 1px solid #ced4da;
                            border-radius: 4px;
                            font-size: 12px;
                        }
                        .part-item .part-controls .btn-remove {
                            background: none;
                            color: #adb5bd;
                            border: 1px solid #dee2e6;
                            border-radius: 4px;
                            padding: 4px 8px;
                            cursor: pointer;
                            font-size: 12px;
                            transition: color 0.15s, border-color 0.15s;
                        }
                        .part-item .part-controls .btn-remove:hover {
                            color: #dc3545;
                            border-color: #dc3545;
                        }
                        .service-parts {
                            display: none;
                        }
                        .service-parts.active {
                            display: block !important;
                        }
                        .service-parts-header {
                            display: flex;
                            align-items: center;
                            padding: 7px 12px;
                            margin: 10px 0 4px 0;
                            background: #eef2fb;
                            border-left: 4px solid #003d7a;
                            border-radius: 4px;
                            font-weight: 600;
                            font-size: 12px;
                            color: #003d7a;
                            letter-spacing: 0.3px;
                            text-transform: uppercase;
                        }
                        .service-parts-header i {
                            margin-right: 7px;
                            font-size: 13px;
                        }
                        #required-parts-container {
                            border: 1px solid #dee2e6;
                            border-radius: 6px 6px 0 0;
                            padding: 10px 12px;
                            max-height: 420px;
                            overflow-y: auto;
                            background: #f8f9fa;
                        }
                        #required-parts-container::-webkit-scrollbar { width: 5px; }
                        #required-parts-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 3px; }
                        #required-parts-container::-webkit-scrollbar-thumb { background: #c1c9d6; border-radius: 3px; }

                        /* ── Shared action bar (bottom of both sections) ── */
                        .section-action-bar {
                            display: flex;
                            justify-content: flex-end;
                            align-items: center;
                            gap: 8px;
                            padding: 7px 10px;
                            background: #f1f3f5;
                            border: 1px solid #dee2e6;
                            border-top: none;
                            border-radius: 0 0 6px 6px;
                        }
                        .action-btn {
                            display: inline-flex;
                            align-items: center;
                            gap: 5px;
                            padding: 5px 13px;
                            font-size: 12px;
                            font-weight: 600;
                            border-radius: 4px;
                            cursor: pointer;
                            transition: opacity .15s, background .15s;
                            white-space: nowrap;
                        }
                        .action-btn:hover { opacity: .85; }
                        .action-btn--primary {
                            background: #003d7a;
                            color: #fff;
                            border: none;
                        }
                        .action-btn--outline-danger {
                            background: #fff;
                            color: #dc3545;
                            border: 1.5px solid #dc3545;
                        }
                    </style>
                    
                    <!-- Placeholder Message -->
                    <div id="parts-placeholder" style="padding:20px;text-align:center;color:#666;">
                        <i class="fas fa-tools" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <p>Parts will appear here when you select service types above</p>
                    </div>

                    <!-- Oil Change Parts -->
                    <div id="oil-change-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-oil-can"></i> Oil Change — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD30" id="oil_part_1" onchange="updatePartsSummary()">
                            <label for="oil_part_1">
                                Engine Oil HD30
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱114.40</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD40" id="oil_part_2" onchange="updatePartsSummary()">
                            <label for="oil_part_2">
                                Engine Oil HD40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Ultron Touring" id="oil_part_3" onchange="updatePartsSummary()">
                            <label for="oil_part_3">
                                Engine Oil Ultron Touring
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱185.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Blaze Racing" id="oil_part_4" onchange="updatePartsSummary()">
                            <label for="oil_part_4">
                                Engine Oil Blaze Racing
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱210.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil MO30/MO40" id="oil_part_5" onchange="updatePartsSummary()">
                            <label for="oil_part_5">
                                Engine Oil MO30/MO40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱130.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Nomis" id="oil_part_6" onchange="updatePartsSummary()">
                            <label for="oil_part_6">
                                Oil Filter Nomis
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter VIC" id="oil_part_7" onchange="updatePartsSummary()">
                            <label for="oil_part_7">
                                Oil Filter VIC
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱200.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Sakura" id="oil_part_8" onchange="updatePartsSummary()">
                            <label for="oil_part_8">
                                Oil Filter Sakura
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter C-series" id="oil_part_9" onchange="updatePartsSummary()">
                            <label for="oil_part_9">
                                Oil Filter C-series
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱220.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Gasket Maker" id="oil_part_10" onchange="updatePartsSummary()">
                            <label for="oil_part_10">
                                Gasket Maker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tire Repair Parts -->
                    <div id="tire-repair-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-circle-notch"></i> Tire Repair — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Rubber" id="tire_part_1" onchange="updatePartsSummary()">
                            <label for="tire_part_1">
                                Tire Valve Rubber
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱45.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Steel" id="tire_part_2" onchange="updatePartsSummary()">
                            <label for="tire_part_2">
                                Tire Valve Steel
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱60.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP1 Patch (Med)" id="tire_part_3" onchange="updatePartsSummary()">
                            <label for="tire_part_3">
                                MP1 Patch (Med)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱35.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP2 Patch (Large)" id="tire_part_4" onchange="updatePartsSummary()">
                            <label for="tire_part_4">
                                MP2 Patch (Large)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱50.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="CT20 Radial Patch" id="tire_part_5" onchange="updatePartsSummary()">
                            <label for="tire_part_5">
                                CT20 Radial Patch
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱75.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Valkarn Cement" id="tire_part_6" onchange="updatePartsSummary()">
                            <label for="tire_part_6">
                                Valkarn Cement
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱90.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Calibration Parts -->
                    <div id="calibration-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-tachometer-alt"></i> Calibration — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Hydrotur (oil/lube)" id="cal_part_1" onchange="updatePartsSummary()">
                            <label for="cal_part_1">
                                Hydrotur (oil/lube)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (sealant)" id="cal_part_2" onchange="updatePartsSummary()">
                            <label for="cal_part_2">
                                MP Grease (sealant)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Standard Gauge (from accessories)" id="cal_part_3" onchange="updatePartsSummary()">
                            <label for="cal_part_3">
                                Standard Gauge (from accessories)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱350.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- General Maintenance Parts -->
                    <div id="general-maintenance-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-wrench"></i> General Maintenance — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease" id="gm_part_1" onchange="updatePartsSummary()">
                            <label for="gm_part_1">
                                MP Grease
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="WD-40" id="gm_part_2" onchange="updatePartsSummary()">
                            <label for="gm_part_2">
                                WD-40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Petromate Oil" id="gm_part_3" onchange="updatePartsSummary()">
                            <label for="gm_part_3">
                                Petromate Oil
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱135.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Small)" id="gm_part_4" onchange="updatePartsSummary()">
                            <label for="gm_part_4">
                                Armor All (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Big)" id="gm_part_5" onchange="updatePartsSummary()">
                            <label for="gm_part_5">
                                Armor All (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱320.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="VS1 Protector (Small)" id="gm_part_6" onchange="updatePartsSummary()">
                            <label for="gm_part_6">
                                VS1 Protector (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱160.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="VS1 Protector (Big)" id="gm_part_7" onchange="updatePartsSummary()">
                            <label for="gm_part_7">
                                VS1 Protector (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱300.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Chamois/Kanebo" id="gm_part_8" onchange="updatePartsSummary()">
                            <label for="gm_part_8">
                                Chamois/Kanebo
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Engine Repair Parts -->
                    <div id="engine-repair-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-cogs"></i> Engine Repair — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD30" id="eng_part_1" onchange="updatePartsSummary()">
                            <label for="eng_part_1">
                                Engine Oil HD30
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱114.40</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil HD40" id="eng_part_2" onchange="updatePartsSummary()">
                            <label for="eng_part_2">
                                Engine Oil HD40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Ultron" id="eng_part_3" onchange="updatePartsSummary()">
                            <label for="eng_part_3">
                                Engine Oil Ultron
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱185.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Blaze Racing" id="eng_part_4" onchange="updatePartsSummary()">
                            <label for="eng_part_4">
                                Engine Oil Blaze Racing
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱210.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Engine Oil Trekker" id="eng_part_5" onchange="updatePartsSummary()">
                            <label for="eng_part_5">
                                Engine Oil Trekker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Nomis" id="eng_part_6" onchange="updatePartsSummary()">
                            <label for="eng_part_6">
                                Oil Filter Nomis
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter VIC" id="eng_part_7" onchange="updatePartsSummary()">
                            <label for="eng_part_7">
                                Oil Filter VIC
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱200.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter Sakura" id="eng_part_8" onchange="updatePartsSummary()">
                            <label for="eng_part_8">
                                Oil Filter Sakura
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Oil Filter C-series" id="eng_part_9" onchange="updatePartsSummary()">
                            <label for="eng_part_9">
                                Oil Filter C-series
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱220.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Regular" id="eng_part_10" onchange="updatePartsSummary()">
                            <label for="eng_part_10">
                                Coolant Regular
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱110.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Green" id="eng_part_11" onchange="updatePartsSummary()">
                            <label for="eng_part_11">
                                Coolant Green
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱115.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Pink" id="eng_part_12" onchange="updatePartsSummary()">
                            <label for="eng_part_12">
                                Coolant Pink
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Gasket Maker" id="eng_part_13" onchange="updatePartsSummary()">
                            <label for="eng_part_13">
                                Gasket Maker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Brake Service Parts -->
                    <div id="brake-service-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-compact-disc"></i> Brake Service — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Fluid 900ml" id="brake_part_1" onchange="updatePartsSummary()">
                            <label for="brake_part_1">
                                Brake Fluid 900ml
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">900ml</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱160.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Fluid Med" id="brake_part_2" onchange="updatePartsSummary()">
                            <label for="brake_part_2">
                                Brake Fluid Med
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">500ml</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Fluid Small" id="brake_part_3" onchange="updatePartsSummary()">
                            <label for="brake_part_3">
                                Brake Fluid Small
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">250ml</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Brake Cleaner Hardex" id="brake_part_4" onchange="updatePartsSummary()">
                            <label for="brake_part_4">
                                Brake Cleaner Hardex
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Electrical Service Parts -->
                    <div id="electrical-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-bolt"></i> Electrical Service — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="WD-40" id="elec_part_1" onchange="updatePartsSummary()">
                            <label for="elec_part_1">
                                WD-40
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Petromate Oil" id="elec_part_2" onchange="updatePartsSummary()">
                            <label for="elec_part_2">
                                Petromate Oil
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱135.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (for terminals)" id="elec_part_3" onchange="updatePartsSummary()">
                            <label for="elec_part_3">
                                MP Grease (for terminals)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Air Conditioning Parts -->
                    <div id="air-conditioning-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-snowflake"></i> Air Conditioning — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Green" id="ac_part_1" onchange="updatePartsSummary()">
                            <label for="ac_part_1">
                                Coolant Green
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱115.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Coolant Pink" id="ac_part_2" onchange="updatePartsSummary()">
                            <label for="ac_part_2">
                                Coolant Pink
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="AC Filter (Oil/Fuel Filter variants)" id="ac_part_3" onchange="updatePartsSummary()">
                            <label for="ac_part_3">
                                AC Filter (Oil/Fuel Filter variants)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱250.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="O-rings (from accessories)" id="ac_part_4" onchange="updatePartsSummary()">
                            <label for="ac_part_4">
                                O-rings (from accessories)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 set</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱45.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Transmission Service Parts -->
                    <div id="transmission-service-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-cog"></i> Transmission Service — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="ATF Premium" id="trans_part_1" onchange="updatePartsSummary()">
                            <label for="trans_part_1">
                                ATF Premium
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱185.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="ATF HTF" id="trans_part_2" onchange="updatePartsSummary()">
                            <label for="trans_part_2">
                                ATF HTF
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1L</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱195.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Transmission Filter (Fuel/Oil Filter variants)" id="trans_part_3" onchange="updatePartsSummary()">
                            <label for="trans_part_3">
                                Transmission Filter (Fuel/Oil Filter variants)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱240.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Gasket Maker" id="trans_part_4" onchange="updatePartsSummary()">
                            <label for="trans_part_4">
                                Gasket Maker
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Suspension Repair Parts -->
                    <div id="suspension-repair-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-car-crash"></i> Suspension Repair — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (for bushings/ball joints)" id="sus_part_1" onchange="updatePartsSummary()">
                            <label for="sus_part_1">
                                MP Grease (for bushings/ball joints)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Shock Absorber (if stocked)" id="sus_part_2" onchange="updatePartsSummary()">
                            <label for="sus_part_2">
                                Shock Absorber (if stocked)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱1850.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Wheel Alignment Parts -->
                    <div id="wheel-alignment-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-dot-circle"></i> Wheel Alignment — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Rubber" id="wheel_part_1" onchange="updatePartsSummary()">
                            <label for="wheel_part_1">
                                Tire Valve Rubber
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱45.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Valve Steel" id="wheel_part_2" onchange="updatePartsSummary()">
                            <label for="wheel_part_2">
                                Tire Valve Steel
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱60.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Alignment Bolts/Wheel Weights (from accessories)" id="wheel_part_3" onchange="updatePartsSummary()">
                            <label for="wheel_part_3">
                                Alignment Bolts/Wheel Weights (from accessories)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 set</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Battery Replacement Parts -->
                    <div id="battery-replacement-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-car-battery"></i> Battery Replacement — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Car Battery (if stocked)" id="bat_part_1" onchange="updatePartsSummary()">
                            <label for="bat_part_1">
                                Car Battery (if stocked)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱3820.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="MP Grease (small packs for terminals)" id="bat_part_2" onchange="updatePartsSummary()">
                            <label for="bat_part_2">
                                MP Grease (small packs for terminals)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱89.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Diagnostic Check Parts -->
                    <div id="diagnostic-check-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-stethoscope"></i> Diagnostic Check — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="OBD Scanner (tool)" id="diag_part_1" onchange="updatePartsSummary()">
                            <label for="diag_part_1">
                                OBD Scanner (tool)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱2500.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Diagnostic Printout Paper (office supply)" id="diag_part_2" onchange="updatePartsSummary()">
                            <label for="diag_part_2">
                                Diagnostic Printout Paper (office supply)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 set</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱25.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Detailing / Cleaning Parts -->
                    <div id="detailing-cleaning-parts" class="service-parts" style="display: none;">
                        <div class="service-parts-header"><i class="fas fa-spray-can"></i> Detailing / Cleaning — Required Parts</div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Clean N Shine Shampoo" id="detail_part_1" onchange="updatePartsSummary()">
                            <label for="detail_part_1">
                                Clean N Shine Shampoo
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 bottle</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Small)" id="detail_part_2" onchange="updatePartsSummary()">
                            <label for="detail_part_2">
                                Armor All (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱180.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Armor All (Big)" id="detail_part_3" onchange="updatePartsSummary()">
                            <label for="detail_part_3">
                                Armor All (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱320.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Black (Small)" id="detail_part_4" onchange="updatePartsSummary()">
                            <label for="detail_part_4">
                                Tire Black (Small)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Small</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Tire Black (Big)" id="detail_part_5" onchange="updatePartsSummary()">
                            <label for="detail_part_5">
                                Tire Black (Big)
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">Big</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱280.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Chamois/Kanebo" id="detail_part_6" onchange="updatePartsSummary()">
                            <label for="detail_part_6">
                                Chamois/Kanebo
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener Neo Shaldan" id="detail_part_7" onchange="updatePartsSummary()">
                            <label for="detail_part_7">
                                Air Freshener Neo Shaldan
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱120.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener California Scents" id="detail_part_8" onchange="updatePartsSummary()">
                            <label for="detail_part_8">
                                Air Freshener California Scents
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱150.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener Little Trees" id="detail_part_9" onchange="updatePartsSummary()">
                            <label for="detail_part_9">
                                Air Freshener Little Trees
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 pc</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱95.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="part-item">
                            <input type="checkbox" name="required_parts_checkboxes[]" value="Air Freshener Glade Spray" id="detail_part_10" onchange="updatePartsSummary()">
                            <label for="detail_part_10">
                                Air Freshener Glade Spray
                                <span style="color: #666; font-size: 11px; margin-left: 8px;">1 can</span>
                                <span style="background: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Required</span>
                                <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">Stock</span>
                                <span style="color: #007bff; font-size: 11px; margin-left: 8px;">₱110.00</span>
                            </label>
                            <div class="part-controls">
                                <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1" onchange="updatePartsSummary()">
                                <input type="text" name="required_parts_remarks[]" placeholder="Remarks" value="">
                                <button type="button" onclick="this.parentElement.parentElement.remove(); updatePartsSummary();" class="btn-remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                        </div>

                        <!-- Other (Manual Input) — shown OUTSIDE the overwritten container so it persists -->
                        <div id="other-inline-section" style="display:none; padding:8px 12px; border:1px solid #e5e7eb; border-radius:6px; margin-top:8px;">
                            <div class="service-parts-header" style="border-left:4px solid #f59e0b; color:#374151; margin-bottom:8px; padding:8px 0;">
                                <i class="fas fa-edit" style="color:#f59e0b; margin-right:8px;"></i> Custom Service Input
                            </div>

                            <!-- ① Custom service name -->
                            <div style="margin:0 0 12px 0;">
                                <label style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:4px;">
                                    <i class="fas fa-tag" style="color:#f59e0b; margin-right:4px;"></i>
                                    Custom Service Name <span style="color:#dc3545;">*</span>
                                </label>
                                <input type="text"
                                       id="service_type_other"
                                       name="service_type_other"
                                       placeholder='e.g. "Rustproofing", "Glass Replacement"'
                                       autocomplete="off"
                                       style="width:100%; max-width:420px; padding:8px 10px; border:1.5px solid #f59e0b; border-radius:5px; font-size:13px;"
                                       oninput="updatePaymentSummary();"
                                       onfocus="this.style.borderColor='#d97706'"
                                       onblur="this.style.borderColor=this.value.trim()?'#16a34a':'#f59e0b'">
                                <div id="other-service-name-error" style="font-size:11px; color:#dc3545; margin-top:3px; display:none;">
                                    <i class="fas fa-exclamation-circle"></i> Please enter the service name.
                                </div>
                            </div>

                            <!-- ② Parts input -->
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:6px;">
                                    <i class="fas fa-tools" style="color:#6b7280; margin-right:4px;"></i>
                                    Required Parts / Materials
                                </label>

                                <!-- Tab switcher -->
                                <div style="display:inline-flex; border:1.5px solid #e5e7eb; border-radius:5px; overflow:hidden; margin-bottom:8px;">
                                    <button type="button" id="other-tab-inventory"
                                            onclick="switchOtherPartsTab('inventory')"
                                            style="padding:5px 13px; font-size:12px; font-weight:600; background:#003d7a; color:#fff; border:none; cursor:pointer;">
                                        <i class="fas fa-boxes"></i> From Inventory
                                    </button>
                                    <button type="button" id="other-tab-freetext"
                                            onclick="switchOtherPartsTab('freetext')"
                                            style="padding:5px 13px; font-size:12px; font-weight:600; background:#fff; color:#374151; border:none; border-left:1.5px solid #e5e7eb; cursor:pointer;">
                                        <i class="fas fa-pencil-alt"></i> Other Part
                                    </button>
                                </div>

                                <!-- From Inventory panel -->
                                <div id="other-panel-inventory" style="margin-bottom:6px;">
                                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                        <select id="other-inventory-select"
                                                style="flex:1; min-width:200px; max-width:300px; padding:6px 8px; border:1.5px solid #ced4da; border-radius:4px; font-size:12px;">
                                            <option value="">— Select from inventory —</option>
                                            <?php foreach ($merch_inventory as $item): ?>
                                            <option value="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                    data-stock="<?php echo (int)($item['stock_level'] ?? 0); ?>">
                                                <?php echo htmlspecialchars($item['product_name']); ?>
                                                (Stock: <?php echo (int)($item['stock_level'] ?? 0); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                            <?php if (empty($merch_inventory)): ?>
                                            <option disabled>No merchandise in stock</option>
                                            <?php endif; ?>
                                        </select>
                                        <input type="number" id="other-inventory-qty" min="1" max="999" value="1"
                                               style="width:56px; padding:6px; border:1.5px solid #ced4da; border-radius:4px; font-size:12px; text-align:center;">
                                        <button type="button" onclick="addOtherInventoryPart()"
                                                style="padding:6px 12px; background:#003d7a; color:#fff; border:none; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer;">
                                            <i class="fas fa-plus"></i> Add
                                        </button>
                                    </div>
                                    <div style="font-size:11px; color:#6b7280; margin-top:3px;">Only in-stock merchandise shown.</div>
                                </div>

                                <!-- Free-text panel -->
                                <div id="other-panel-freetext" style="display:none; margin-bottom:6px;">
                                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                        <input type="text" id="other-freetext-name" placeholder="Part / material name"
                                               style="flex:1; min-width:160px; padding:6px 8px; border:1.5px solid #ced4da; border-radius:4px; font-size:12px;">
                                        <input type="number" id="other-freetext-qty" min="1" max="999" value="1"
                                               style="width:56px; padding:6px; border:1.5px solid #ced4da; border-radius:4px; font-size:12px; text-align:center;">
                                        <input type="text" id="other-freetext-remarks" placeholder="Remarks"
                                               style="width:120px; padding:6px 8px; border:1.5px solid #ced4da; border-radius:4px; font-size:12px;">
                                        <button type="button" onclick="addOtherFreeTextPart()"
                                                style="padding:6px 12px; background:#003d7a; color:#fff; border:none; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer;">
                                            <i class="fas fa-plus"></i> Add
                                        </button>
                                    </div>
                                    <div style="font-size:11px; color:#6b7280; margin-top:3px;">For parts not in inventory — flagged as <strong>Other Part</strong>.</div>
                                </div>

                                <!-- Added parts list -->
                                <div id="other-manual-parts-list" style="margin-top:4px;"></div>
                            </div>

                            
                            <!-- Hidden flags -->
                            <input type="hidden" name="is_manual_service" id="is_manual_service" value="0">
                            <input type="hidden" name="manual_service_flag" value="1">
                            <input type="hidden" name="manual_validation_required" id="manual_validation_required" value="0">
                        </div>

                        <!-- ── Required Parts action bar (bottom-right, matches Service Type) ── -->
                        <div id="required-parts-buttons" class="section-action-bar">
                            <button type="button" onclick="selectAllRequiredParts()" class="action-btn action-btn--primary">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" onclick="clearRequiredParts()" class="action-btn action-btn--outline-danger">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>

                        <!-- Parts Summary -->
                        <div id="parts-summary" style="margin-top:8px; padding:8px 12px; background:#f0f4ff; border:1px solid #c8d8f8; border-radius:5px; display:flex; align-items:center; gap:18px; flex-wrap:wrap;">
                            <span style="font-size:13px; color:#495057;">
                                <i class="fas fa-list-check" style="color:#003d7a; margin-right:4px;"></i>
                                <strong>Selected Parts:</strong> <span id="selected-parts-count" style="color:#003d7a; font-weight:700;">0</span>
                            </span>
                            <span style="color:#dee2e6;">|</span>
                            <span style="font-size:13px; color:#495057;">
                                <i class="fas fa-box" style="color:#6c757d; margin-right:4px;"></i>
                                <strong>Merchandise:</strong> <span id="merchandise-count" style="color:#6c757d; font-weight:700;">0</span>
                            </span>
                            <span style="color:#dee2e6;">|</span>
                            <span style="font-size:13px; color:#495057;">
                                <i class="fas fa-pencil" style="color:#ffc107; margin-right:4px;"></i>
                                <strong>Manual:</strong> <span id="manual-count" style="color:#856404; font-weight:700;">0</span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estimated Duration (minutes) <small class="text-muted">- For planning purposes only (pricing is per service)</small></label>
                        <input type="number" name="estimated_duration" class="form-input" 
                               value="<?php echo htmlspecialchars($edit_job['estimated_duration'] ?? '60'); ?>" min="15" max="480" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Service Description</label>
                    <textarea name="service_description" class="form-textarea" 
                              placeholder="Describe the service needed..." required><?php echo htmlspecialchars($edit_job['service_description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select" id="payment_method" onchange="handlePaymentMethodChange()" required>
                        <option value="">Select payment method</option>
                        <?php if (!empty($payment_methods)): ?>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo htmlspecialchars($method['method_name']); ?>"
                                        <?php echo ($edit_job['payment_method'] ?? '') == $method['method_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($method['method_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="Cash"        <?php echo ($edit_job['payment_method'] ?? '') == 'Cash'        ? 'selected' : ''; ?>>Cash</option>
                            <option value="Card"        <?php echo ($edit_job['payment_method'] ?? '') == 'Card'        ? 'selected' : ''; ?>>Card (Debit/Credit)</option>
                            <option value="E-Wallet"    <?php echo ($edit_job['payment_method'] ?? '') == 'E-Wallet'    ? 'selected' : ''; ?>>E-Wallet</option>
                            <option value="E-Fuel Card" <?php echo ($edit_job['payment_method'] ?? '') == 'E-Fuel Card' ? 'selected' : ''; ?>>E-Fuel Card</option>
                            <option value="Credit"      <?php echo ($edit_job['payment_method'] ?? '') == 'Credit'      ? 'selected' : ''; ?>>Credit (Utang)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- ══ PAYMENT PROCESSING PANEL ══════════════════════════════════════ -->
                <div id="payment_fields" style="display:none; margin-top:4px;">

                    <!-- Cost Breakdown -->
                    <div style="background:#f0f4ff; border:1px solid #c8d8f8; border-radius:7px; padding:14px 16px; margin-bottom:14px;">
                        <div style="font-size:13px; font-weight:700; color:#003d7a; margin-bottom:10px;">
                            <i class="fas fa-calculator"></i> Cost Breakdown
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                            <div style="background:#fff; border-radius:5px; padding:10px; border:1px solid #dce8ff; text-align:center;">
                                <div style="font-size:10px; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Labor (Service Fee)</div>
                                <div style="font-size:16px; font-weight:800; color:#003d7a;">₱<span id="labor_cost_display">0.00</span></div>
                            </div>
                            <div style="background:#fff; border-radius:5px; padding:10px; border:1px solid #dce8ff; text-align:center;">
                                <div style="font-size:10px; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Parts (Merchandise)</div>
                                <div style="font-size:16px; font-weight:800; color:#495057;">₱<span id="parts_cost_display">0.00</span></div>
                            </div>
                            <div style="background:#003d7a; border-radius:5px; padding:10px; text-align:center;">
                                <div style="font-size:10px; color:#a8c4e8; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Grand Total</div>
                                <div style="font-size:16px; font-weight:800; color:#fff;">₱<span id="total_amount_display">0.00</span></div>
                            </div>
                        </div>
                        <!-- Hidden inputs submitted with form -->
                        <input type="hidden" name="labor_cost"  id="labor_cost_input"  value="0">
                        <input type="hidden" name="parts_cost"  id="parts_cost_input"  value="0">
                        <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                    </div>

                    <!-- ── CASH ─────────────────────────────────────────────────────── -->
                    <div id="pm_cash" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-money-bill-wave" style="color:#28a745;margin-right:5px;"></i>
                                Amount Tendered <span style="color:#dc3545;">*</span>
                            </label>
                            <div style="display:flex;align-items:center;border:1.5px solid #ced4da;border-radius:5px;overflow:hidden;max-width:220px;">
                                <span style="background:#e9ecef;padding:9px 12px;font-weight:700;color:#495057;border-right:1px solid #ced4da;">₱</span>
                                <input type="number" name="amount_paid" id="amount_paid"
                                       class="form-input" style="border:none;margin:0;border-radius:0;"
                                       step="0.01" min="0" placeholder="0.00"
                                       oninput="recalcPayment()">
                            </div>
                        </div>
                        <div id="sukli_group" style="display:none; margin-bottom:12px;">
                            <label class="form-label">Change (Sukli)</label>
                            <div style="padding:10px 14px; background:#d4edda; border:1.5px solid #c3e6cb; border-radius:5px; display:flex; align-items:center; gap:8px; max-width:220px;">
                                <i class="fas fa-coins" style="color:#155724;"></i>
                                <strong style="color:#155724; font-size:16px;">₱<span id="sukli_display">0.00</span></strong>
                            </div>
                            <input type="hidden" name="sukli" id="sukli_input" value="0">
                        </div>
                    </div>

                    <!-- ── CARD ─────────────────────────────────────────────────────── -->
                    <div id="pm_card" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-credit-card" style="color:#003d7a;margin-right:5px;"></i>
                                Card Reference No. <span style="font-size:11px;color:#6c757d;">(optional)</span>
                            </label>
                            <input type="text" name="card_ref" id="card_ref"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="e.g. 1234-5678-XXXX">
                            <small style="color:#6c757d;">Swipe / insert card on POS terminal. Exact service cost will be charged.</small>
                        </div>
                    </div>

                    <!-- ── E-WALLET ─────────────────────────────────────────────────── -->
                    <div id="pm_ewallet" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-mobile-alt" style="color:#17a2b8;margin-right:5px;"></i>
                                E-Wallet Reference No. <span style="color:#dc3545;">*</span>
                            </label>
                            <input type="text" name="ewallet_ref" id="ewallet_ref"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="e.g. GCash / Maya ref no.">
                            <small style="color:#6c757d;">Scan QR or input reference number after transfer confirmation.</small>
                        </div>
                    </div>

                    <!-- ── E-FUEL CARD ──────────────────────────────────────────────── -->
                    <div id="pm_efuel" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-gas-pump" style="color:#dc3545;margin-right:5px;"></i>
                                E-Fuel Card ID <span style="color:#dc3545;">*</span>
                            </label>
                            <input type="text" name="efuel_card_id" id="efuel_card_id"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="Enter card ID / number">
                            <small style="color:#6c757d;">Stored value will be deducted automatically.</small>
                        </div>
                    </div>

                    <!-- ── CREDIT ───────────────────────────────────────────────────── -->
                    <div id="pm_credit" style="display:none;">
                        <div style="background:#fff8e1; border:1.5px solid #ffc107; border-radius:6px; padding:12px 14px; margin-bottom:10px;">
                            <div style="font-size:12px; font-weight:700; color:#856404; margin-bottom:6px;">
                                <i class="fas fa-exclamation-triangle"></i> Credit Transaction
                            </div>
                            <div style="font-size:12px; color:#856404;">
                                Transaction will be saved as <strong>Pending Payment</strong> and auto-linked to the Receivables module.
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user" style="color:#856404;margin-right:5px;"></i>
                                Credit Customer Name <span style="color:#dc3545;">*</span>
                            </label>
                            <input type="text" name="credit_customer_name" id="credit_customer_name"
                                   class="form-input" style="max-width:280px;"
                                   placeholder="Customer name for credit account">
                        </div>
                    </div>

                    <!-- ── Payment Status Badge ─────────────────────────────────────── -->
                    <div style="margin-top:8px;">
                        <label class="form-label">Payment Status</label>
                        <div id="payment_status_display"
                             style="display:inline-block; padding:7px 18px; border-radius:20px; font-weight:700; font-size:13px; background:#fff3cd; color:#856404; border:1.5px solid #ffc107;">
                            Pending
                        </div>
                    </div>

                </div><!-- /#payment_fields -->

                <div class="form-group">
                    <label class="form-label">Additional Notes</label>
                    <textarea name="additional_notes" class="form-textarea" 
                              placeholder="Special instructions or notes..."></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Create Job Order
                    </button>
                    <button type="reset" class="btn-secondary" onclick="resetForm()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    
    <!-- Job Order Status Tracker Tab -->
    <div id="tracker-tab" class="tab-content <?php echo (in_array($role, ['staff', 'cashier', 'pump_attendant']) ? '' : 'active'); ?>">
        <div class="job-card">
            <h2 style="margin-bottom: 30px; color: #003d7a;">
                <i class="fas fa-tasks"></i> Job Order Status Tracker
            </h2>
            
            <!-- Tracker Sub-tabs -->
            <div class="tracker-tabs" style="display: flex; border-bottom: 2px solid #e9ecef; margin-bottom: 20px;">
                <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
                    <!-- Staff: Simplified tabs with Approved/Validated as main hub -->
                    <button class="tracker-tab-btn active" onclick="switchTrackerTab('pending-validation')" data-tab="pending-validation">
                        <i class="fas fa-clock"></i> Pending Validation
                        <span class="badge"><?php echo count(array_filter($job_orders, fn($j) => $j['validation_status'] === 'Pending Validation')); ?></span>
                    </button>
                    <button class="tracker-tab-btn" onclick="switchTrackerTab('approved-validated')" data-tab="approved-validated">
                        <i class="fas fa-check-double"></i> Approved Validated
                    </button>
                    <button class="tracker-tab-btn" onclick="switchTrackerTab('rejected')" data-tab="rejected">
                        <i class="fas fa-times-circle"></i> Rejected
                        <span class="badge"><?php echo count(array_filter($job_orders, fn($j) => $j['status'] === 'Rejected')); ?></span>
                    </button>
                            </div>

            <!-- Pending Validation Tab Content -->
            <div id="pending-validation-content" class="tracker-tab-content <?php echo (in_array($role, ['staff', 'cashier', 'pump_attendant']) ? 'active' : 'active'); ?>">
                
                <?php 
                $pending_jobs = array_filter($job_orders, fn($j) => $j['validation_status'] === 'Pending Validation');
                if (empty($pending_jobs)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">
                        No pending validation job orders found.
                    </p>
                <?php else: ?>
                    <table class="job-table">
                        <thead>
                            <tr>
                                <th>Job Order ID</th>
                                <th>Customer</th>
                                <th>Service Type</th>
                                <th>Assigned Mechanic</th>
                                <th>Timestamp</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_jobs as $job): ?>
                                <tr>
                                    <td><strong>#<?php echo $job['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($job['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($job['service_type']); ?></td>
                                    <td><?php echo htmlspecialchars($job['mechanic_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo date('M j, Y h:i A', strtotime($job['created_at'])); ?></td>
                                    <td>
                                        <span class="status-badge status-pending">PENDING VALIDATION</span>
                                    </td>
                                    <td>
                                            <button onclick="validateJobOrder(<?php echo $job['id']; ?>, 'approve')" class="btn btn-success btn-sm">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button onclick="validateJobOrder(<?php echo $job['id']; ?>, 'reject')" class="btn btn-danger btn-sm">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Approved Validated Tab Content -->
            <div id="approved-validated-content" class="tracker-tab-content">
                
                <?php 
                $approved_validated_jobs = array_filter($job_orders, fn($j) => $j['validation_status'] === 'Approved');
                if (empty($approved_validated_jobs)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">
                        No approved validated job orders found.
                    </p>
                <?php else: ?>
                    <table class="job-table">
                        <thead>
                            <tr>
                                <th>Job Order ID</th>
                                <th>Customer</th>
                                <th>Service Type</th>
                                <th>Assigned Mechanic</th>
                                <th>Approved Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approved_validated_jobs as $job): ?>
                                <tr>
                                    <td><strong>#<?php echo $job['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($job['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($job['service_type']); ?></td>
                                    <td><?php echo htmlspecialchars($job['mechanic_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo date('M j, Y h:i A', strtotime($job['validated_at'] ?? $job['updated_at'])); ?></td>
                                    <td>
                                        <?php
                                        // Debug: Log status display values
                                        error_log("Status Display Debug - Job ID: {$job['id']}, Raw Status: '{$job['status']}', Validation Status: '{$job['validation_status']}'");
                                        
                                        $status_class = 'status-pending';
                                        $status_text = 'PENDING VALIDATION';
                                        
                                        switch($job['status'] ?? 'Pending') {
                                            case 'In Progress':
                                                $status_class = 'status-in-progress';
                                                $status_text = 'IN PROGRESS';
                                                error_log("Status Display Match - Case: In-Progress, Text: IN PROGRESS");
                                                break;
                                            case 'Completed':
                                                $status_class = 'status-completed';
                                                $status_text = 'COMPLETED';
                                                error_log("Status Display Match - Case: Completed, Text: COMPLETED");
                                                break;
                                            case 'Pending':
                                                // Only show APPROVED VALIDATED if status is empty/null or 'Pending' and validation_status is Approved
                                                if ($job['validation_status'] === 'Approved') {
                                                    $status_class = 'status-approved-validated';
                                                    $status_text = 'APPROVED VALIDATED';
                                                    error_log("Status Display Default - Approved Job Order, Text: APPROVED VALIDATED");
                                                } else {
                                                    $status_class = 'status-pending';
                                                    $status_text = 'PENDING VALIDATION';
                                                    error_log("Status Display Default - Fallback, Text: PENDING VALIDATION");
                                                }
                                                break;
                                            case 'Rejected':
                                                $status_class = 'status-rejected';
                                                $status_text = 'REJECTED';
                                                error_log("Status Display Match - Case: Rejected, Text: REJECTED");
                                                break;
                                            default:
                                                if (empty($job['status'])) {
                                                    if ($job['validation_status'] === 'Approved') {
                                                        $status_class = 'status-approved-validated';
                                                        $status_text = 'APPROVED VALIDATED';
                                                        error_log("Status Display Default - Approved Job Order, Text: APPROVED VALIDATED");
                                                    } else {
                                                        $status_class = 'status-pending';
                                                        $status_text = 'PENDING VALIDATION';
                                                        error_log("Status Display Default - Fallback, Text: PENDING VALIDATION");
                                                    }
                                                } else {
                                                    // If status has a value but doesn't match known cases, show it
                                                    $status_class = 'status-pending';
                                                    $status_text = strtoupper($job['status']);
                                                    error_log("Status Display Unknown - Raw Status: '{$job['status']}', Text: " . strtoupper($job['status']));
                                                }
                                        }
                                        error_log("Final Status Display - Class: '$status_class', Text: '$status_text'");
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($status_text); ?></span>
                                    </td>
                                    <td>
                                        <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
                                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                                <?php if (empty($job['status']) || $job['status'] === 'Pending'): ?>
                                                    <button onclick="updateJobStatus(<?php echo $job['id']; ?>, 'In Progress')" class="btn btn-primary btn-sm" style="width: 100%;">
                                                        <i class="fas fa-play"></i> In Progress
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (empty($job['status']) || in_array($job['status'], ['Pending', 'In Progress'])): ?>
                                                    <button onclick="updateJobStatus(<?php echo $job['id']; ?>, 'Completed')" class="btn btn-success btn-sm" style="width: 100%;">
                                                        <i class="fas fa-check"></i> Complete
                                                    </button>
                                                <?php endif; ?>
                                                <button onclick="viewJobDetails(<?php echo $job['id']; ?>)" class="btn btn-info btn-sm" style="width: 100%;">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Rejected Tab Content -->
            <div id="rejected-content" class="tracker-tab-content">
                
                <?php 
                $rejected_jobs = array_filter($job_orders, fn($j) => $j['status'] === 'Rejected');
                if (empty($rejected_jobs)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">
                        No rejected job orders found.
                    </p>
                <?php else: ?>
                    <table class="job-table">
                        <thead>
                            <tr>
                                <th>Job Order ID</th>
                                <th>Customer</th>
                                <th>Service Type</th>
                                <th>Remarks</th>
                                <th>Status</th>
                                <th>Rejected Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejected_jobs as $job): ?>
                                <tr>
                                    <td><strong>#<?php echo $job['id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($job['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($job['service_type']); ?></td>
                                    <td><?php echo htmlspecialchars($job['rejection_remarks'] ?? 'No remarks provided'); ?></td>
                                    <td>
                                        <span class="status-badge status-rejected">REJECTED</span>
                                    </td>
                                    <td><?php echo date('M j, Y h:i A', strtotime($job['rejected_date'] ?? $job['updated_at'])); ?></td>
                                    <td>
                                        <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
                                            <button onclick="editRejectedJobOrder(<?php echo $job['id']; ?>)" class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (in_array($role, ['admin', 'superadmin'])): ?>
    <!-- Transparency Tab (Admin/SuperAdmin Only) -->
    <div id="transparency-tab" class="tab-content active">
        <div class="job-card">
            <h2 style="margin-bottom: 30px; color: #003d7a;">
                <i class="fas fa-eye"></i> Transparency Tab
            </h2>
            
            <!-- Admin/SuperAdmin View: System-level comprehensive data -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #003d7a;">
                    <h4 style="margin-bottom: 15px; color: #003d7a;">Manager View Level</h4>
                    <p><strong>Role:</strong> <?php echo ucfirst($role); ?></p>
                    <p><strong>Access:</strong> All station job orders + oversight</p>
                    <p><strong>Station:</strong> <?php echo htmlspecialchars($station_id); ?></p>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #28a745;">
                    <h4 style="margin-bottom: 15px; color: #28a745;">Station Job Order Summary</h4>
                    <p><strong>Total Job Orders:</strong> <?php echo count($job_orders); ?></p>
                    <p><strong>Pending:</strong> <?php echo count(array_filter($job_orders, fn($j) => $j['status'] === 'Pending')); ?></p>
                    <p><strong>In Progress:</strong> <?php echo count(array_filter($job_orders, fn($j) => $j['status'] === 'In Progress')); ?></p>
                    <p><strong>Completed:</strong> <?php echo count(array_filter($job_orders, fn($j) => $j['status'] === 'Completed')); ?></p>
                    <p><strong>Total Revenue:</strong> ₱<?php echo number_format(array_sum(array_map(fn($j) => $j['actual_cost'] ?? $j['estimated_cost'] ?? 0, $job_orders)), 2); ?></p>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107;">
                    <h4 style="margin-bottom: 15px; color: #856404;">Mechanic Workload Analysis</h4>
                    <?php 
                    $mechanic_workload = [];
                    $mechanic_performance = [];
                    foreach ($job_orders as $job) {
                        if ($job['assigned_mechanic_id']) {
                            $mechanic_name = $job['mechanic_name'] ?? 'Unassigned';
                            if (!isset($mechanic_workload[$mechanic_name])) {
                                $mechanic_workload[$mechanic_name] = ['total' => 0, 'active' => 0, 'completed' => 0, 'revenue' => 0];
                            }
                            $mechanic_workload[$mechanic_name]['total']++;
                            if (in_array($job['status'], ['Pending', 'In Progress'])) {
                                $mechanic_workload[$mechanic_name]['active']++;
                            }
                            if ($job['status'] === 'Completed') {
                                $mechanic_workload[$mechanic_name]['completed']++;
                                $mechanic_workload[$mechanic_name]['revenue'] += $job['actual_cost'] ?? $job['estimated_cost'] ?? 0;
                            }
                        }
                    }
                    ?>
                    <?php foreach ($mechanic_workload as $mechanic => $data): ?>
                        <p><strong><?php echo htmlspecialchars($mechanic); ?>:</strong></p>
                        <p style="margin-left: 20px; font-size: 12px;">
                            Active: <?php echo $data['active']; ?> | 
                            Completed: <?php echo $data['completed']; ?> | 
                            Revenue: ₱<?php echo number_format($data['revenue'], 2); ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Staff Performance Comparison -->
            <div style="background: #e8f5e8; padding: 20px; border-radius: 8px; border: 1px solid #c3e6c3; margin-bottom: 30px;">
                <h4 style="margin-bottom: 15px; color: #2d6a2d;"><i class="fas fa-chart-bar" style="margin-right:6px;"></i> Staff Performance Comparison</h4>
                <?php 
                $staff_performance = [];
                foreach ($job_orders as $job) {
                    if ($job['created_by_name']) {
                        $staff_name = $job['created_by_name'];
                        if (!isset($staff_performance[$staff_name])) {
                            $staff_performance[$staff_name] = ['total' => 0, 'completed' => 0, 'revenue' => 0];
                        }
                        $staff_performance[$staff_name]['total']++;
                        if ($job['status'] === 'Completed') {
                            $staff_performance[$staff_name]['completed']++;
                            $staff_performance[$staff_name]['revenue'] += $job['actual_cost'] ?? $job['estimated_cost'] ?? 0;
                        }
                    }
                }
                ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f1f8f1;">
                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #2d6a2d;">Staff Name</th>
                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid #2d6a2d;">Total Orders</th>
                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid #2d6a2d;">Completed</th>
                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid #2d6a2d;">Completion Rate</th>
                            <th style="padding: 10px; text-align: right; border-bottom: 2px solid #2d6a2d;">Revenue Generated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff_performance as $staff_name => $data): ?>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #e0e0e0;"><?php echo htmlspecialchars($staff_name); ?></td>
                                <td style="padding: 8px; text-align: center; border-bottom: 1px solid #e0e0e0;"><?php echo $data['total']; ?></td>
                                <td style="padding: 8px; text-align: center; border-bottom: 1px solid #e0e0e0;"><?php echo $data['completed']; ?></td>
                                <td style="padding: 8px; text-align: center; border-bottom: 1px solid #e0e0e0;"><?php echo round($data['completed'] * 100 / $data['total'], 1); ?>%</td>
                                <td style="padding: 8px; text-align: right; border-bottom: 1px solid #e0e0e0;">₱<?php echo number_format($data['revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function switchTab(tabName, triggerButton = null) {
    try {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        // Remove active from all buttons
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

        // Show selected tab
        const selectedTab = document.getElementById(tabName + '-tab');
        if (!selectedTab) { console.warn(`Tab '${tabName}' not found`); return; }
        selectedTab.classList.add('active');

        // Activate corresponding button
        const button = triggerButton
            || document.querySelector(`.job-tabs .tab-btn[onclick*="'${tabName}'"]`)
            || null;
        if (button) button.classList.add('active');

        // Update page subtitle for staff
        const subtitle = document.getElementById('page-subtitle');
        if (subtitle) {
            const labels = {
                'encode':  'Encode Job Order — fill in customer, vehicle, service type and parts',
                'tracker': 'Job Order Status Tracker — monitor your submitted job orders'
            };
            if (labels[tabName]) subtitle.textContent = labels[tabName];
        }

        // Highlight matching sidebar sub-item
        document.querySelectorAll('.sidebar-sub-item').forEach(el => {
            el.classList.remove('active');
            if (el.getAttribute('data-tab') === tabName) el.classList.add('active');
        });

        // Save to sessionStorage — NO hash manipulation here to avoid hashchange loops
        sessionStorage.setItem('activeMainTab', tabName);
    } catch (error) {
        console.error('Error switching tab:', error);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    try {
        const role = '<?php echo $role; ?>';
        const isStaff = ['staff', 'cashier', 'pump_attendant'].includes(role);

        // ── 1. Read tracker_tab from URL query param (PHP redirect after submit) ──
        const urlParams = new URLSearchParams(window.location.search);
        const trackerTabFromUrl = urlParams.get('tracker_tab');
        if (trackerTabFromUrl) {
            sessionStorage.setItem('activeTrackerTab', trackerTabFromUrl);
            sessionStorage.setItem('activeMainTab', 'tracker');
            // Clean the URL — remove query param, keep hash
            history.replaceState(null, '', window.location.pathname + (window.location.hash || ''));
        }

        // ── 2. Determine which MAIN tab to show ───────────────────────────────
        const allowedTabs = getAllowedTabs();
        const savedMain   = sessionStorage.getItem('activeMainTab');
        let targetMain    = (savedMain && allowedTabs.includes(savedMain)) ? savedMain : getDefaultTab();

        // Hash override (e.g. #tracker, #encode from PHP redirect)
        const hashTab = window.location.hash ? window.location.hash.substring(1) : '';
        if (hashTab && allowedTabs.includes(hashTab)) {
            targetMain = hashTab;
            sessionStorage.setItem('activeMainTab', targetMain);
        }

        // Activate main tab (no animation flash — direct class manipulation)
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.job-tabs .tab-btn').forEach(b => b.classList.remove('active'));
        const mainTabEl  = document.getElementById(targetMain + '-tab');
        if (mainTabEl) mainTabEl.classList.add('active');
        const mainBtnEl  = document.querySelector(`.job-tabs .tab-btn[onclick*="'${targetMain}'"]`);
        if (mainBtnEl) mainBtnEl.classList.add('active');

        // ── 3. Determine which TRACKER sub-tab to show ───────────────────────
        if (targetMain === 'tracker') {
            const validTrackerTabs = ['pending-validation', 'approved-validated', 'rejected'];
            const savedTracker     = sessionStorage.getItem('activeTrackerTab');
            const targetTracker    = (savedTracker && validTrackerTabs.includes(savedTracker))
                                     ? savedTracker : 'pending-validation';
            // Apply immediately — no timeout needed since we're in DOMContentLoaded
            switchTrackerTab(targetTracker);
        }

        // ── 4. Sidebar highlight ──────────────────────────────────────────────
        document.querySelectorAll('.sidebar-sub-item').forEach(el => {
            el.classList.remove('active');
            if (el.getAttribute('data-tab') === targetMain) el.classList.add('active');
        });

        // ── 5. Sidebar sub-item click interception ────────────────────────────
        document.querySelectorAll('.sidebar-sub-item[data-tab]').forEach(function(link) {
            const tab = link.getAttribute('data-tab');
            if (!tab || !allowedTabs.includes(tab)) return;
            link.addEventListener('click', function(e) {
                const currentPage = window.location.pathname.split('/').pop();
                if (currentPage !== 'joborder.php') return;
                e.preventDefault();
                switchTab(tab, null);
            });
        });

        // ── 6. Handle browser back/forward ────────────────────────────────────
        window.addEventListener('hashchange', function() {
            const h = window.location.hash ? window.location.hash.substring(1) : '';
            if (h && allowedTabs.includes(h)) switchTab(h, null);
        });

    } catch (error) {
        console.error('Error initializing tabs:', error);
    }
});

function getDefaultTab() {
    try {
        const role = '<?php echo $role; ?>';
        if (['staff', 'cashier', 'pump_attendant'].includes(role)) return 'encode';
        return 'transparency'; // admin/superadmin
    } catch (error) {
        console.error('Error getting default tab:', error);
        return 'encode'; // Safe fallback
    }
}

function getAllowedTabs() {
    try {
        const role = '<?php echo $role; ?>';
        if (['staff', 'cashier', 'pump_attendant'].includes(role)) return ['encode', 'tracker'];
        return ['transparency']; // admin/superadmin
    } catch (error) {
        console.error('Error getting allowed tabs:', error);
        return ['encode']; // Safe fallback
    }
}

// Toast Notification System
let toastActions = [];

function showToast(message, type = 'success', options = {}) {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        console.error('Toast container not found');
        return null;
    }
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-info-circle';
    
    let toastContent = `
        <div class="toast-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div class="toast-message">${message}</div>
        <button class="toast-close" onclick="hideToast(this.parentElement)">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add action buttons if provided
    if (options.actions && options.actions.length > 0) {
        // Store actions globally with unique ID
        const actionId = Date.now() + Math.random();
        toastActions[actionId] = options.actions;
        
        const actionsHtml = options.actions.map((action, index) => 
            `<button class="${action.class}" onclick="executeToastAction('${actionId}', ${index})">${action.label}</button>`
        ).join('');
        
        toastContent += `<div class="toast-actions" style="margin-top: 10px; display: flex; gap: 8px;">${actionsHtml}</div>`;
        
        // Store action ID on toast element
        toast.setAttribute('data-action-id', actionId);
    }
    
    toast.innerHTML = toastContent;
    toastContainer.appendChild(toast);
    
    // Trigger animation
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);
    
    // Auto-hide after 5 seconds if no actions
    if (!options.actions || options.actions.length === 0) {
        setTimeout(() => {
            hideToast(toast);
        }, 5000);
    }
    
    return toast;
}

function executeToastAction(actionId, actionIndex) {
    const actions = toastActions[actionId];
    if (actions && actions[actionIndex]) {
        const action = actions[actionIndex];
        if (typeof action.action === 'function') {
            action.action();
        }
    }
}

function hideToast(toast) {
    if (!toast) return;
    
    toast.classList.add('hide');
    
    // Clean up stored actions
    const actionId = toast.getAttribute('data-action-id');
    if (actionId && toastActions[actionId]) {
        delete toastActions[actionId];
    }
    
    setTimeout(() => {
        if (toast.parentElement) {
            toast.parentElement.removeChild(toast);
        }
    }, 300);
}

function showSuccessToast(message) {
    return showToast(message, 'success');
}

function showInfoToast(message) {
    return showToast(message, 'info');
}

function showErrorToast(message) {
    return showToast(message, 'error');
}

function updateJobStatus(jobId, newStatus) {
    try {
        if (!newStatus) return;
        
        // Show confirmation toast instead of browser alert
        const confirmToast = showToast(
            `Set Job Order #${jobId} to ${newStatus}?`,
            'info',
            {
                actions: [
                    {
                        label: 'Yes, proceed',
                        class: 'btn btn-success btn-sm',
                        action: () => {
                            // Remove confirmation toast
                            hideToast(confirmToast);
                            
                            // Show processing toast
                            const processingToast = showToast(
                                `Updating Job Order #${jobId} to ${newStatus}...`,
                                'info'
                            );
                            
                            // Submit form immediately (no inline update needed)
                            submitStatusUpdate(jobId, newStatus);
                        }
                    },
                    {
                        label: 'Cancel',
                        class: 'btn btn-secondary btn-sm',
                        action: () => {
                            hideToast(confirmToast);
                        }
                    }
                ]
            }
        );
        
    } catch (error) {
        console.error('Error updating job status:', error);
        showToast('Error updating job status. Please try again.', 'error');
    }
}

function submitStatusUpdate(jobId, newStatus) {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'joborder.php';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_status';
    form.appendChild(actionInput);
    
    const jobIdInput = document.createElement('input');
    jobIdInput.type = 'hidden';
    jobIdInput.name = 'job_id';
    jobIdInput.value = jobId;
    form.appendChild(jobIdInput);
    
    const statusInput = document.createElement('input');
    statusInput.type = 'hidden';
    statusInput.name = 'new_status';
    statusInput.value = newStatus;
    form.appendChild(statusInput);
    
    const notesInput = document.createElement('input');
    notesInput.type = 'hidden';
    notesInput.name = 'notes';
    notesInput.value = `Status changed to ${newStatus}`;
    form.appendChild(notesInput);

    // Preserve current tracker tab across the redirect
    const currentTrackerTab = sessionStorage.getItem('activeTrackerTab') || 'approved-validated';
    const tabInput = document.createElement('input');
    tabInput.type  = 'hidden';
    tabInput.name  = 'tracker_tab';
    tabInput.value = currentTrackerTab;
    form.appendChild(tabInput);
    
    document.body.appendChild(form);
    form.submit();
}


function viewJobDetails(jobId) {
    try {
        // Find job order data from the current table row
        const rows = document.querySelectorAll('tr');
        let jobData = null;
        
        rows.forEach(row => {
            const jobIdCell = row.querySelector('td:first-child');
            if (jobIdCell && jobIdCell.textContent.includes(`#${jobId}`)) {
                const cells = row.querySelectorAll('td');
                jobData = {
                    id: jobId,
                    jobOrderId: cells[0].textContent.trim(),
                    customer: cells[1].textContent.trim(),
                    serviceType: cells[2].textContent.trim(),
                    assignedMechanic: cells[3].textContent.trim(),
                    timestamp: cells[4].textContent.trim(),
                    status: cells[5].textContent.trim()
                };
            }
        });
        
        if (!jobData) {
            showToast('Job order not found', 'error');
            return;
        }
        
        // Show read-only modal with job details
        showJobDetailsModal(jobData);
        
    } catch (error) {
        console.error('Error viewing job details:', error);
        showToast('Error viewing job details. Please try again.', 'error');
    }
}

function showJobDetailsModal(jobData) {
    // Create modal HTML
    const modalHtml = `
        <div id="viewJobModal" class="modal" style="display: flex; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div class="modal-content" style="background-color: #fefefe; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #003d7a; padding-bottom: 15px;">
                    <h2 style="color: #003d7a; margin: 0;">
                        <i class="fas fa-clipboard-list"></i> Job Order Details
                    </h2>
                    <button onclick="closeViewJobModal()" style="background: #dc3545; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h4 style="color: #003d7a; margin-bottom: 15px;">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </h4>
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Job Order ID:</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">${jobData.jobOrderId}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Customer:</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">${jobData.customer}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Service Type:</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">${jobData.serviceType}</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div>
                        <h4 style="color: #003d7a; margin-bottom: 15px;">
                            <i class="fas fa-cogs"></i> Status & Assignment
                        </h4>
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Assigned Mechanic:</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">${jobData.assignedMechanic}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Approved Time:</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">${jobData.timestamp}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Current Status:</td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;">
                                    <span style="padding: 4px 8px; border-radius: 3px; background: #007bff; color: white;">
                                        ${jobData.status}
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid #17a2b8;">
                    <p style="margin: 0; color: #666; font-style: italic;">
                        <i class="fas fa-info-circle"></i> This is a read-only view. No changes can be made to this job order.
                    </p>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeViewJobModal() {
    const modal = document.getElementById('viewJobModal');
    if (modal) {
        modal.remove();
    }
}

function editRejectedJobOrder(jobId) {
    try {
        // Fetch job order data from the current table row
        const rows = document.querySelectorAll('tr');
        let jobData = null;
        
        rows.forEach(row => {
            const jobIdCell = row.querySelector('td:first-child');
            if (jobIdCell && jobIdCell.textContent.includes(`#${jobId}`)) {
                const cells = row.querySelectorAll('td');
                jobData = {
                    id: jobId,
                    customer: cells[1].textContent.trim(),
                    serviceType: cells[2].textContent.trim(),
                    remarks: cells[3].textContent.trim()
                };
            }
        });
        
        if (!jobData) {
            showToast('Job order not found', 'error');
            return;
        }
        
        // Show rejection remarks modal first
        showRejectionRemarksModal(jobData);
        
    } catch (error) {
        console.error('Error editing job order:', error);
        showToast('Error editing job order. Please try again.', 'error');
    }
}

function showRejectionRemarksModal(jobData) {
    const modalHtml = `
        <div id="rejectionRemarksModal" class="modal" style="display: flex; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div class="modal-content" style="background-color: #fefefe; padding: 30px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #dc3545; padding-bottom: 15px;">
                    <h2 style="color: #dc3545; margin: 0;">
                        <i class="fas fa-exclamation-triangle"></i> Rejection Remarks
                    </h2>
                    <button onclick="closeRejectionRemarksModal()" style="background: #6c757d; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                
                <div style="margin-bottom: 25px;">
                    <h4 style="color: #333; margin-bottom: 15px;">Job Order #${jobData.id}</h4>
                    <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
                        <h5 style="color: #721c24; margin: 0 0 10px 0;">Rejection Reason:</h5>
                        <p style="margin: 0; color: #721c24; font-style: italic;">${jobData.remarks}</p>
                    </div>
                    
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px;">
                        <h5 style="color: #856404; margin: 0 0 10px 0;">What to Fix:</h5>
                        <ul style="margin: 0; padding-left: 20px; color: #856404;">
                            <li>Review the rejection reason above</li>
                            <li>Correct the errors in the job order form</li>
                            <li>Ensure all required information is complete</li>
                            <li>Verify service type and customer details</li>
                        </ul>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button onclick="closeRejectionRemarksModal()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button onclick="proceedToEdit(${jobData.id})" style="background: #ffc107; color: #212529; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">
                        <i class="fas fa-edit"></i> Edit Job Order
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeRejectionRemarksModal() {
    const modal = document.getElementById('rejectionRemarksModal');
    if (modal) {
        modal.remove();
    }
}

function proceedToEdit(jobId) {
    closeRejectionRemarksModal();
    
    // Switch to encode tab and load job order for editing
    switchTab('encode');
    
    // Load job order data for editing
    setTimeout(() => {
        loadJobOrderForEdit(jobId);
    }, 500);
}

function loadJobOrderForEdit(jobId) {
    // Create a form to fetch job order data
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'joborder.php';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'edit_job_order';
    form.appendChild(actionInput);
    
    const jobIdInput = document.createElement('input');
    jobIdInput.type = 'hidden';
    jobIdInput.name = 'job_id';
    jobIdInput.value = jobId;
    form.appendChild(jobIdInput);
    
    document.body.appendChild(form);
    form.submit();
}

function resetForm() {
    try {
        if (confirm('Reset all form fields?')) {
            const form = document.querySelector('form');
            if (form) {
                form.reset();
            }
        }
    } catch (error) {
        console.error('Error resetting form:', error);
    }
}

function handleServiceTypeSelection() {
    try {
        const serviceTypeCheckboxes = document.querySelectorAll('input[name="service_types[]"]');
        const serviceTypeOther = document.getElementById('service_type_other');
        
        // Update payment summary when service types change
        updatePaymentSummary();
        
        if (!serviceTypeCheckboxes || !serviceTypeOther) {
            console.warn('Service type elements not found');
            return;
        }
        
        // Check if "Other" is specifically selected
        let otherSelected = false;
        serviceTypeCheckboxes.forEach(checkbox => {
            if (checkbox.checked && checkbox.value === 'Other') {
                otherSelected = true;
            }
        });
        
        if (otherSelected) {
            serviceTypeOther.style.display = 'block';
            serviceTypeOther.required = true;
        } else {
            serviceTypeOther.style.display = 'none';
            serviceTypeOther.required = false;
            serviceTypeOther.value = '';
        }
        
        // Update selected services display
        updateSelectedServicesDisplay();
        
    } catch (error) {
        console.error('Error handling service type selection:', error);
    }
}

function updateSelectedServicesDisplay() {
    try {
        const serviceTypeCheckboxes = document.querySelectorAll('input[name="service_types[]"]:checked');
        const selectedServices = Array.from(serviceTypeCheckboxes).map(cb => cb.value);
        
        // Auto-populate required parts dropdown based on selected services
        populateRequiredPartsDropdown(selectedServices);
        
    } catch (error) {
        console.error('Error updating selected services display:', error);
        // Provide immediate fallback on error
        const serviceTypeCheckboxes = document.querySelectorAll('input[name="service_types[]"]:checked');
        const selectedServices = Array.from(serviceTypeCheckboxes).map(cb => cb.value);
        provideFallbackParts(selectedServices);
    }
}

// Helper function to get service IDs from service names
async function getServiceIds(serviceNames) {
    try {
        // First, get all services from the database
        const response = await fetch('backend/api/service_parts_mapping.php?action=get_services', {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success && result.data) {
            // Create a mapping of service names to IDs
            const serviceMap = {};
            result.data.forEach(service => {
                serviceMap[service.service_name] = service.id;
                // Also map by service_key for flexibility
                serviceMap[service.service_key] = service.id;
            });
            
            // Convert service names to IDs
            const serviceIds = serviceNames
                .map(name => serviceMap[name])
                .filter(id => id !== undefined); // Remove undefined mappings
            
            return serviceIds.join(',');
        } else {
            console.error('Failed to get services:', result.error);
            return '';
        }
    } catch (error) {
        console.error('Error getting service IDs:', error);
        return '';
    }
}

async function populateRequiredPartsDropdown(serviceTypes) {
    try {
        const autoPopulateText = document.getElementById('auto-populate-text');
        const requiredPartsContainer = document.getElementById('required-parts-container');
        
        // Safety check - if containers don't exist, exit gracefully
        if (!requiredPartsContainer) {
            console.error('Required parts container missing');
            return;
        }
        
        // Always show containers - never hide them
        requiredPartsContainer.style.display = 'block';
        
        if (!serviceTypes || serviceTypes.length === 0) {
            // No service types selected - show placeholder
            autoPopulateText.textContent = 'Select service types above to auto-populate parts';
            const placeholder = document.getElementById('parts-placeholder');
            if (placeholder) placeholder.style.removeProperty('display');
            updatePartsSummary();
            return;
        }
        
        // Check if "Other" is selected
        const hasOther = serviceTypes && serviceTypes.some(service => 
            service && (service.toLowerCase().includes('other') || service.toLowerCase().includes('manual'))
        );
        
        if (hasOther && serviceTypes.length === 1) {
            // Only "Other" selected — show the manual input section, leave container alone
            autoPopulateText.innerHTML = '<i class="fas fa-edit" style="color:#f59e0b"></i> Manual entry — type service name and add parts below';
            const otherSection = document.getElementById('other-inline-section');
            if (otherSection) {
                otherSection.style.setProperty('display', 'block', 'important');
                otherSection.style.visibility = 'visible';
            }
            const placeholder = document.getElementById('parts-placeholder');
            if (placeholder) placeholder.style.setProperty('display', 'none', 'important');
            updatePartsSummary();
            return;
        }
        
        // Load parts instantly using immediate fallback, then try API in background
        autoPopulateText.textContent = `Loading parts for ${serviceTypes.join(', ')}...`;
        
        // Use immediate fallback first for instant response
        provideImmediateFallbackParts(serviceTypes);
        
        // Then try API in background for better data
        const serviceIds = await getServiceIds(serviceTypes);
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 2000); // 2 second timeout
        
        try {
            const response = await fetch(
                `backend/api/service_parts_mapping.php?action=get_parts_by_service&service_ids=${encodeURIComponent(serviceIds)}`,
                {
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );
            
            clearTimeout(timeoutId);
            
            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success && result.data) {
                // Replace with API data if successful
                requiredPartsContainer.innerHTML = '';
                
                // Add parts as simple list with checkboxes
                let partIndex = 0;
                result.data.forEach((part) => {
                    if (part && part.part_name) {
                        const partName = part.part_name;
                        const isMerchandise = part.is_merchandise || false;
                        const inInventory = part.in_inventory || false;
                        const stockStatus = part.stock_status || 'unknown';
                        const currentStock = part.current_stock || 0;
                        const defaultQty = part.default_quantity || 1;
                                
                                const partDiv = document.createElement('div');
                                partDiv.style.cssText = 'margin: 6px 0; padding: 10px 15px; background: white; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center; transition: all 0.2s ease;';
                                partDiv.innerHTML = `
                                    <input type="checkbox" name="required_parts_checkboxes[]" value="${partName}" 
                                           id="required_part_${partIndex}" onchange="updatePartsSummary()" 
                                           style="margin-right: 12px; transform: scale(1.3);">
                                    <label for="required_part_${partIndex}" style="cursor: pointer; flex: 1; margin-left: 8px; font-weight: 500; color: #333;">
                                        ${partName}
                                        ${isMerchandise ? '<span style="color: #28a745; font-size: 11px; margin-left: 8px;">● Merchandise</span>' : '<span style="color: #6c757d; font-size: 11px; margin-left: 8px;">● Service Part</span>'}
                                        ${stockStatus === 'out_of_stock' ? '<span style="color: #dc3545; font-size: 11px; margin-left: 5px;"><i class="fas fa-times-circle"></i> Out of Stock</span>' : ''}
                                        ${stockStatus === 'low_stock' ? '<span style="color: #ffc107; font-size: 11px; margin-left: 5px;"><i class="fas fa-exclamation-triangle"></i> Low Stock (' + currentStock + ')</span>' : ''}
                                        ${stockStatus === 'in_stock' ? '<span style="color: #28a745; font-size: 11px; margin-left: 5px;"><i class="fas fa-check-circle"></i> In Stock (' + currentStock + ')</span>' : ''}
                                        ${part.is_required ? '<span style="color: #007bff; font-size: 11px; margin-left: 5px;"><i class="fas fa-star"></i> Required</span>' : ''}
                                    </label>
                                    <div class="part-controls">
                                        <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="${defaultQty}"
                                               style="width: 60px; padding: 6px; border: 1px solid #ddd; border-radius: 3px; text-align: center;">
                                        <input type="text" name="required_parts_remarks[]" placeholder="Remarks" 
                                               style="width: 140px; padding: 6px; border: 1px solid #ddd; border-radius: 3px;">
                                        <input type="hidden" name="required_parts_names[]" value="${partName}">
                                        <button type="button" onclick="removePartRow(this)" style="background: #dc3545; color: white; border: none; border-radius: 3px; padding: 6px 10px; cursor: pointer;" title="Remove this part">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                `;
                                requiredPartsContainer.appendChild(partDiv);
                                partIndex++;
                    }
                });
                
                // Update auto-populate indicator with statistics
                autoPopulateText.textContent = `Loaded ${result.count} parts from database`;
                const requiredParts = result.data.filter(p => p.is_required).length;
                if (requiredParts > 0) {
                    autoPopulateText.textContent += ` (${requiredParts} required)`;
                }
                
                updatePartsSummary();
                console.log('Required parts populated from API:', result.data);
                
            } else {
                // No parts found - keep fallback data
                autoPopulateText.textContent = `Using fallback parts for ${serviceTypes.length} service type(s)`;
                console.log('No parts found from API, using fallback:', serviceTypes);
            }
            
        } catch (fetchError) {
            clearTimeout(timeoutId);
            console.error('Fetch error:', fetchError);
            // Keep the immediate fallback data that's already loaded
            autoPopulateText.textContent = `Using instant parts for ${serviceTypes.length} service type(s)`;
        }
        
    } catch (error) {
        console.error('Error populating required parts dropdown:', error);
        
        // Comprehensive error handling with fallback
        const autoPopulateText = document.getElementById('auto-populate-text');
        const requiredPartsContainer = document.getElementById('required-parts-container');
        
        if (autoPopulateText) {
            autoPopulateText.textContent = 'Error loading parts - please try again';
        }
        
        if (requiredPartsContainer) {
            requiredPartsContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#dc3545;">Error loading parts. Please try again or use manual input.</div>';
        }
        
        // Show manual section as fallback
        const manualPartsSection = document.getElementById('manual-parts-section');
        if (manualPartsSection) {
            manualPartsSection.style.display = 'block';
        }
    }
}

// Add manual part row function
function addManualPartRow() {
    const manualPartsList = document.getElementById('manual-parts-list');
    const partIndex = Date.now(); // Use timestamp for unique ID
    
    const partRow = document.createElement('div');
    partRow.style.cssText = 'margin: 8px 0; padding: 10px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center;';
    partRow.innerHTML = `
        <input type="text" name="manual_parts_name[]" placeholder="Enter part name" 
               style="flex: 1; margin-right: 10px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;" required>
        <input type="number" name="manual_parts_qty[]" placeholder="Qty" min="1" max="999" value="1"
               style="width: 60px; margin-right: 5px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
        <input type="text" name="manual_parts_remarks[]" placeholder="Remarks" 
               style="width: 120px; margin-right: 5px; padding: 4px; border: 1px solid #ddd; border-radius: 3px;">
        <button type="button" onclick="removeManualPartRow(this)" style="background: #dc3545; color: white; border: none; border-radius: 3px; padding: 4px 8px; cursor: pointer;">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    manualPartsList.appendChild(partRow);
    updatePartsSummary();
}

// ── Other service section helpers ──────────────────────────────────────

function switchOtherPartsTab(tab) {
    const invPanel  = document.getElementById('other-panel-inventory');
    const ftPanel   = document.getElementById('other-panel-freetext');
    const invBtn    = document.getElementById('other-tab-inventory');
    const ftBtn     = document.getElementById('other-tab-freetext');
    const activeStyle   = 'padding:6px 16px; font-size:12px; font-weight:600; background:#003d7a; color:#fff; border:none; cursor:pointer;';
    const inactiveStyle = 'padding:6px 16px; font-size:12px; font-weight:600; background:#fff; color:#374151; border:none; border-left:1.5px solid #e5e7eb; cursor:pointer;';
    if (tab === 'inventory') {
        invPanel.style.display = 'block';
        ftPanel.style.display  = 'none';
        invBtn.style.cssText   = activeStyle;
        ftBtn.style.cssText    = inactiveStyle;
    } else {
        invPanel.style.display = 'none';
        ftPanel.style.display  = 'block';
        invBtn.style.cssText   = inactiveStyle;
        ftBtn.style.cssText    = activeStyle.replace('border:none;', 'border:none; border-left:1.5px solid #e5e7eb;');
    }
}

function addOtherInventoryPart() {
    const sel     = document.getElementById('other-inventory-select');
    const qtyEl   = document.getElementById('other-inventory-qty');
    const name    = sel.value.trim();
    const stock   = parseInt(sel.selectedOptions[0]?.getAttribute('data-stock') || '0');
    const qty     = parseInt(qtyEl.value) || 1;
    if (!name) { sel.style.borderColor = '#dc3545'; setTimeout(() => sel.style.borderColor = '#ced4da', 1500); return; }
    _appendOtherPart(name, qty, '', 'inventory');
    sel.value   = '';
    qtyEl.value = 1;
}

function addOtherFreeTextPart() {
    const nameEl    = document.getElementById('other-freetext-name');
    const qtyEl     = document.getElementById('other-freetext-qty');
    const remarksEl = document.getElementById('other-freetext-remarks');
    const name      = nameEl.value.trim();
    if (!name) { nameEl.style.borderColor = '#dc3545'; setTimeout(() => nameEl.style.borderColor = '#ced4da', 1500); return; }
    _appendOtherPart(name, parseInt(qtyEl.value) || 1, remarksEl.value.trim(), 'other_part');
    nameEl.value = ''; qtyEl.value = 1; remarksEl.value = '';
}

function _appendOtherPart(name, qty, remarks, source) {
    const list = document.getElementById('other-manual-parts-list');
    const row  = document.createElement('div');
    const badge = source === 'inventory'
        ? '<span style="font-size:10px; background:#dcfce7; color:#166534; padding:2px 7px; border-radius:10px; font-weight:600; margin-left:6px;"><i class="fas fa-boxes"></i> Inventory</span>'
        : '<span style="font-size:10px; background:#fef3c7; color:#92400e; padding:2px 7px; border-radius:10px; font-weight:600; margin-left:6px;"><i class="fas fa-pencil-alt"></i> Other Part</span>';
    row.style.cssText = 'display:flex; align-items:center; gap:8px; padding:8px 10px; margin:5px 0; background:#fff; border:1px solid #e5e7eb; border-radius:6px;';
    row.innerHTML = `
        <span style="flex:1; font-size:13px; font-weight:500; color:#111;">${name}${badge}</span>
        <input type="hidden" name="manual_parts_name[]"    value="${name}">
        <input type="hidden" name="manual_parts_service[]" value="other">
        <input type="number" name="manual_parts_qty[]"     value="${qty}" min="1" max="999"
               style="width:58px; padding:5px 6px; border:1px solid #ced4da; border-radius:4px; font-size:13px; text-align:center;"
               onchange="updatePartsSummary()">
        <input type="number" name="manual_parts_price[]"   value="0" min="0" step="0.01" placeholder="Price"
               style="width:80px; padding:5px 6px; border:1px solid #ced4da; border-radius:4px; font-size:13px; text-align:right;"
               onchange="updatePaymentSummary()" title="Unit price (optional)">
        <input type="text"   name="manual_parts_remarks[]" value="${remarks}" placeholder="Remarks"
               style="width:120px; padding:5px 8px; border:1px solid #ced4da; border-radius:4px; font-size:12px;">
        <button type="button" onclick="this.closest('div').remove(); updatePartsSummary();" class="btn-remove">
            <i class="fas fa-times"></i>
        </button>
    `;
    list.appendChild(row);
    updatePartsSummary();
}

// Keep old addOtherPartRow as alias for backward compat
function addOtherPartRow() { addOtherFreeTextPart(); }

// Update payment summary when Other price changes
function updateOtherServicePrice() { updatePaymentSummary(); }

// Update manual validation indicators for managers/admins
function updateManualValidationIndicators() {
    const serviceNameField = document.getElementById('service_type_other');
    const validationServiceName = document.getElementById('validation-service-name');
    const validationPartsCount = document.getElementById('validation-parts-count');
    
    if (validationServiceName && serviceNameField) {
        validationServiceName.textContent = serviceNameField.value.trim() || 'Not specified';
    }
    
    if (validationPartsCount) {
        const manualPartsCount = document.querySelectorAll('input[name="manual_parts_name[]"]').length;
        validationPartsCount.textContent = manualPartsCount;
    }
}

// Enhanced validation for manual service entries
function validateManualServiceEntry() {
    const serviceNameField = document.getElementById('service_type_other');
    const manualPartsList = document.getElementById('other-manual-parts-list');
    const errorDiv = document.getElementById('other-service-name-error');
    
    let isValid = true;
    let errorMessage = '';
    
    // Validate service name
    if (!serviceNameField || !serviceNameField.value.trim()) {
        isValid = false;
        errorMessage = 'Please enter the service name.';
        if (errorDiv) {
            errorDiv.style.display = 'block';
            errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + errorMessage;
        }
        if (serviceNameField) serviceNameField.style.borderColor = '#dc3545';
    } else {
        if (errorDiv) errorDiv.style.display = 'none';
        if (serviceNameField) serviceNameField.style.borderColor = '#16a34a';
    }
    
    // Validate at least one part is added
    const manualParts = document.querySelectorAll('input[name="manual_parts_name[]"]');
    if (manualParts.length === 0) {
        isValid = false;
        if (!errorMessage) errorMessage = 'Please add at least one part or material.';
        
        // Show parts validation error
        if (manualPartsList) {
            manualPartsList.style.border = '2px solid #dc3545';
            manualPartsList.style.borderRadius = '5px';
            setTimeout(() => {
                manualPartsList.style.border = '';
                manualPartsList.style.borderRadius = '';
            }, 3000);
        }
    }
    
    return isValid;
}

// Remove manual part row
function removeManualPartRow(button) { button.parentElement.remove(); updatePartsSummary(); }

// Remove auto-populated part row
function removePartRow(button) { button.parentElement.remove(); updatePartsSummary(); }

// Update parts summary function for checkbox format
function updatePartsSummary() {
    // ── Merchandise: checked auto-populated checkboxes in active service sections ──
    let autoParts = 0;
    document.querySelectorAll('.service-parts.active input[name="required_parts_checkboxes[]"]:checked').forEach(() => autoParts++);

    // ── Manual: only rows where the name field has a non-empty value ──────────
    let manualParts = 0;
    document.querySelectorAll('input[name="manual_parts_name[]"]').forEach(inp => {
        if (inp.value && inp.value.trim() !== '') manualParts++;
    });

    // ── Selected Parts = Merchandise + Manual ─────────────────────────────────
    const totalParts = autoParts + manualParts;

    const selectedCount    = document.getElementById('selected-parts-count');
    const merchandiseCount = document.getElementById('merchandise-count');
    const manualCount      = document.getElementById('manual-count');

    if (selectedCount)    selectedCount.textContent   = totalParts;
    if (merchandiseCount) merchandiseCount.textContent = autoParts;
    if (manualCount)      manualCount.textContent      = manualParts;

    window.jobOrderData = window.jobOrderData || {};
    window.jobOrderData.selectedParts = [];
    document.querySelectorAll('.service-parts.active input[name="required_parts_checkboxes[]"]:checked').forEach(cb => {
        window.jobOrderData.selectedParts.push({ name: cb.value, id: cb.id });
    });

    // Keep payment summary in sync whenever parts change
    updatePaymentSummary();
}

// Database-driven fallback parts function with calibration hardcoded fallback
function provideImmediateFallbackParts(serviceTypes) {
    try {
        const autoPopulateText = document.getElementById('auto-populate-text');
        const requiredPartsContainer = document.getElementById('required-parts-container');
        const requiredPartsButtons = document.getElementById('required-parts-buttons');
        const manualPartsSection = document.getElementById('manual-parts-section');
        const partsSummary = document.getElementById('parts-summary');
        
        // Remove hardcoded calibration check - let database handle all parts
        
        // Clear container and show loading state
        requiredPartsContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#666;">Loading parts from database...</div>';
        
        // Show containers
        requiredPartsContainer.style.display = 'block';
        requiredPartsButtons.style.display = 'block';
        manualPartsSection.style.display = 'block';
        partsSummary.style.display = 'block';
        
        // Fetch parts from database API
        const serviceTypesString = serviceTypes.join(',');
        
        fetch(`/group31petron_system_official4/backend/api/service_type_parts_inventory.php?action=get_parts&service_types=${encodeURIComponent(serviceTypesString)}`, {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (result.success && result.data) {
                // Display database parts
                requiredPartsContainer.innerHTML = '';
                
                let partIndex = 0;
                Object.entries(result.data).forEach(([category, parts]) => {
                    if (category && parts && Array.isArray(parts) && parts.length > 0) {
                        // Add parts directly from database
                        parts.forEach((part) => {
                            if (part && (typeof part === 'string' || typeof part === 'object')) {
                                const partName = typeof part === 'object' ? part.name : part;
                                const isMerchandise = typeof part === 'object' ? part.is_merchandise : true;
                                const inInventory = typeof part === 'object' ? part.in_inventory : false;
                                
                                const partDiv = document.createElement('div');
                                partDiv.style.cssText = 'margin: 6px 0; padding: 10px 15px; background: white; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center; transition: all 0.2s ease;';
                                partDiv.innerHTML = `
                                    <input type="checkbox" name="required_parts_checkboxes[]" value="${partName}" 
                                           id="required_part_${partIndex}" onchange="updatePartsSummary()" 
                                           style="margin-right: 12px; transform: scale(1.3);">
                                    <label for="required_part_${partIndex}" style="cursor: pointer; flex: 1; margin-left: 8px; font-weight: 500; color: #333;">
                                        ${partName}
                                        ${isMerchandise ? '<span style="color: #28a745; font-size: 11px; margin-left: 8px;">● Merchandise</span>' : '<span style="color: #6c757d; font-size: 11px; margin-left: 8px;">● Non-merchandise</span>'}
                                        ${isMerchandise && !inInventory ? '<span style="color: #ffc107; font-size: 11px; margin-left: 5px;"><i class="fas fa-exclamation-triangle"></i> Not in inventory</span>' : ''}
                                    </label>
                                    <div class="part-controls">
                                        <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1"
                                               style="width: 60px; padding: 6px; border: 1px solid #ddd; border-radius: 3px; text-align: center;">
                                        <input type="text" name="required_parts_remarks[]" placeholder="Remarks" 
                                               style="width: 140px; padding: 6px; border: 1px solid #ddd; border-radius: 3px;">
                                        <input type="hidden" name="required_parts_names[]" value="${partName}">
                                        <button type="button" onclick="removePartRow(this)" style="background: #dc3545; color: white; border: none; border-radius: 3px; padding: 6px 10px; cursor: pointer;" title="Remove this part">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                `;
                                requiredPartsContainer.appendChild(partDiv);
                                partIndex++;
                            }
                        });
                    }
                });
                
                // Update indicator - removed label
                if (autoPopulateText) {
                    autoPopulateText.textContent = '';
                }
                
                updatePartsSummary();
                
            } else {
                // No parts found - show specific message based on API response
                let noPartsMessage = 'No parts mapped for this service. Please use manual input below.';
                let statusMessage = 'No parts mapped - use manual input';
                
                if (result.message && result.message.includes('No parts found')) {
                    noPartsMessage = 'No parts mapped for selected service types. Please use manual input below.';
                    statusMessage = 'No parts mapped - use manual input';
                } else if (result.message && result.message.includes('Please select service type')) {
                    noPartsMessage = 'Please select a service type first.';
                    statusMessage = 'Select service type';
                }
                
                requiredPartsContainer.innerHTML = `<div style="padding:20px;text-align:center;color:#666;">${noPartsMessage}</div>`;
                if (autoPopulateText) {
                    autoPopulateText.textContent = statusMessage;
                }
            }
        })
        .catch(error => {
            console.error('Database fetch error:', error);
            
            // Provide basic parts fallback based on selected services
            let fallbackParts = [];
            
            serviceTypes.forEach(service => {
                switch(service) {
                    case 'Oil Change':
                        fallbackParts.push('Engine Oil – HD 30', 'Engine Oil – HD 40', 'Engine Oil – Ultron Touring', 'Engine Oil – Blaze Racing', 'Engine Oil – MO 30', 'Engine Oil – MO 40', 'Oil Filter – Nomis', 'Oil Filter – VIC', 'Oil Filter – Sakura', 'Oil Filter – C‑series filters', 'Gasket Maker');
                        break;
                    case 'Tire Repair':
                        fallbackParts.push('Tire Valve Rubber', 'Tire Valve Steel', 'MP1 Patch (Med)', 'MP2 Patch (Large)', 'CT20 Radial Patch', 'Valkarn Cement');
                        break;
                    case 'Calibration':
                        fallbackParts.push('Hydrotur (oil/lube)', 'MP Grease (sealant)', 'Standard Gauge');
                        break;
                    case 'General Maintenance':
                        fallbackParts.push('MP Grease', 'WD‑40', 'Petromate Penetrating Oil', 'Armor All (Small/Big)', 'VS1 Protector (Small/Big)', 'Chamois/Kanebo');
                        break;
                    case 'Engine Repair':
                        fallbackParts.push('Engine Oil – HD series', 'Engine Oil – Ultron', 'Engine Oil – Blaze Racing', 'Engine Oil – Trekker', 'Oil Filter – Nomis', 'Oil Filter – VIC', 'Oil Filter – Sakura', 'Oil Filter – C‑series filters', 'Coolant – Regular', 'Coolant – Green', 'Coolant – Pink', 'Gasket Maker');
                        break;
                    case 'Brake Service':
                        fallbackParts.push('Brake Fluid 900ml', 'Brake Fluid Med', 'Brake Fluid Small', 'Break Cleaner Hardex');
                        break;
                    case 'Electrical':
                        fallbackParts.push('WD‑40', 'Petromate Penetrating Oil', 'MP Grease (for terminals)');
                        break;
                    case 'Air Conditioning':
                        fallbackParts.push('Coolant Green', 'Coolant Pink', 'AC Filter (Oil/Fuel Filter variants)', 'O‑rings (from accessories)');
                        break;
                    case 'Transmission Service':
                        fallbackParts.push('ATF Premium', 'ATF HTF', 'Transmission Filter (Fuel/Oil Filter variants)', 'Gasket Maker');
                        break;
                    case 'Suspension Repair':
                        fallbackParts.push('MP Grease', 'Shock Absorber');
                        break;
                    case 'Wheel Alignment':
                        fallbackParts.push('Tire Valve Rubber', 'Tire Valve Steel', 'Alignment Bolts/Wheel Weights (from accessories)');
                        break;
                    case 'Battery Replacement':
                        fallbackParts.push('Car Battery (if stocked)', 'MP Grease (small packs)');
                        break;
                    case 'Diagnostic Check':
                        fallbackParts.push('OBD Scanner (tool)', 'Diagnostic Printout Paper');
                        break;
                    case 'Detailing / Cleaning':
                        fallbackParts.push('Clean N Shine Shampoo', 'Armor All (Small/Big)', 'Tire Black (Small/Big)', 'Chamois/Kanebo', 'Air Freshener – Neo Shaldan', 'Air Freshener – California Scents', 'Air Freshener – Little Trees', 'Air Freshener – Glade Spray');
                        break;
                    case 'Other':
                        fallbackParts.push('Staff encode manually kung wala sa list');
                        break;
                    default:
                        fallbackParts.push('MP Grease', 'WD‑40', 'Gasket Maker');
                }
            });
            
            // Remove duplicates
            fallbackParts = [...new Set(fallbackParts)];
            
            // Display fallback parts
            if (fallbackParts.length > 0) {
                requiredPartsContainer.innerHTML = '';
                let partIndex = 0;
                
                fallbackParts.forEach((partName) => {
                    const partDiv = document.createElement('div');
                    partDiv.style.cssText = 'margin: 6px 0; padding: 10px 15px; background: white; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center; transition: all 0.2s ease;';
                    partDiv.innerHTML = `
                        <input type="checkbox" name="required_parts_checkboxes[]" value="${partName}" 
                               id="required_part_${partIndex}" onchange="updatePartsSummary()" 
                               style="margin-right: 12px; transform: scale(1.3);">
                        <label for="required_part_${partIndex}" style="cursor: pointer; flex: 1; margin-left: 8px; font-weight: 500; color: #333;">
                            ${partName}
                            <span style="color: #28a745; font-size: 11px; margin-left: 8px;">● Available</span>
                        </label>
                        <div class="part-controls">
                            <input type="number" name="required_parts_qty[]" placeholder="Qty" min="1" max="999" value="1"
                                   style="width: 60px; padding: 6px; border: 1px solid #ddd; border-radius: 3px; text-align: center;">
                            <input type="text" name="required_parts_remarks[]" placeholder="Remarks" 
                                   style="width: 140px; padding: 6px; border: 1px solid #ddd; border-radius: 3px;">
                            <input type="hidden" name="required_parts_names[]" value="${partName}">
                            <button type="button" onclick="removePartRow(this)" style="background: #dc3545; color: white; border: none; border-radius: 3px; padding: 6px 10px; cursor: pointer;" title="Remove this part">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                    requiredPartsContainer.appendChild(partDiv);
                    partIndex++;
                });
                
                if (autoPopulateText) {
                    autoPopulateText.textContent = 'Using fallback parts for ' + serviceTypes.length + ' service type(s)';
                }
            } else {
                requiredPartsContainer.innerHTML = `
                    <div style="padding:20px;text-align:center;color:#dc3545;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <p>Error loading parts from database.</p>
                        <p>Please use manual input below or refresh the page.</p>
                    </div>
                `;
                
                if (autoPopulateText) {
                    autoPopulateText.textContent = 'Database error - use manual input';
                }
            }
            
            updatePartsSummary();
        });
        
    } catch (error) {
        console.error('Error in database fallback:', error);
        // Show manual input only - no hardcoded fallbacks
        requiredPartsContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#dc3545;">Error loading parts. Use manual input below.</div>';
        if (autoPopulateText) {
            autoPopulateText.textContent = 'Error - use manual input';
        }
    }
}

function provideFallbackParts(selectedServices) {
    try {
        // No hardcoded fallbacks - redirect to database-driven function
        provideImmediateFallbackParts(selectedServices);
        
    } catch (error) {
        console.error('Error providing fallback parts:', error);
        
        // Ultimate fallback - just show manual input (no hardcoded parts)
        const requiredPartsContainer = document.getElementById('required-parts-container');
        const manualPartsSection = document.getElementById('manual-parts-section');
        const autoPopulateText = document.getElementById('auto-populate-text');
        
        if (requiredPartsContainer) {
            requiredPartsContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#dc3545;">Error loading parts. Use manual input below.</div>';
        }
        
        if (manualPartsSection) {
            manualPartsSection.style.display = 'block';
        }
        
        if (autoPopulateText) {
            autoPopulateText.textContent = 'Error - use manual input';
        }
    }
}

function toggleManualInput() {
    try {
        const requiredPartsContainer = document.getElementById('required-parts-container');
        const partsListContainer = document.getElementById('parts-list-container');
        const manualPartsContainer = document.getElementById('manual-parts-container');
        const autoPopulateIndicator = document.getElementById('auto-populate-indicator');
        
        if (requiredPartsContainer && partsListContainer && manualPartsContainer && autoPopulateIndicator) {
            // Show manual input, hide auto-populate
            requiredPartsContainer.style.display = 'block';
            partsListContainer.style.display = 'none';
            manualPartsContainer.style.display = 'block';
            autoPopulateIndicator.style.display = 'none';
            
            // Initialize manual parts container
            if (manualPartsContainer.innerHTML.trim() === '') {
                manualPartsContainer.innerHTML = `
                    <div style="margin-bottom: 10px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Manual Parts Entry:</label>
                        <div id="manual-parts-list">
                            <div style="display: flex; align-items: center; margin-bottom: 10px;">
                                <input type="text" name="manual_parts_name[]" placeholder="Part name" style="flex: 1; margin-right: 10px;" oninput="updatePartsSummary()">
                                <input type="number" name="manual_parts_qty[]" placeholder="Qty" min="1" max="999" style="width: 80px; margin-right: 10px;">
                                <input type="text" name="manual_parts_remarks[]" placeholder="Remarks" style="width: 150px; margin-right: 10px;">
                                <button type="button" onclick="addManualPart()" class="btn btn-sm btn-outline">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Error toggling to manual input:', error);
    }
}

function toggleAutoPopulate() {
    try {
        const requiredPartsContainer = document.getElementById('required-parts-container');
        const partsListContainer = document.getElementById('parts-list-container');
        const manualPartsContainer = document.getElementById('manual-parts-container');
        const autoPopulateIndicator = document.getElementById('auto-populate-indicator');
        
        if (requiredPartsContainer && partsListContainer && manualPartsContainer && autoPopulateIndicator) {
            // Show auto-populate, hide manual input
            requiredPartsContainer.style.display = 'block';
            partsListContainer.style.display = 'block';
            manualPartsContainer.style.display = 'none';
            autoPopulateIndicator.style.display = 'block';
        }
    } catch (error) {
        console.error('Error toggling to auto-populate:', error);
    }
}

function addManualPart() {
    try {
        const manualPartsList = document.getElementById('manual-parts-list');
        if (manualPartsList) {
            const newPartDiv = document.createElement('div');
            newPartDiv.className = 'manual-part-item';
            newPartDiv.innerHTML = `
                <div style="display: flex; align-items: center; margin-bottom: 10px;">
                    <input type="text" name="manual_parts_name[]" placeholder="Part name" style="flex: 1; margin-right: 10px;" oninput="updatePartsSummary()">
                    <input type="number" name="manual_parts_qty[]" placeholder="Qty" min="1" max="999" style="width: 80px; margin-right: 10px;">
                    <input type="text" name="manual_parts_remarks[]" placeholder="Remarks" style="width: 150px; margin-right: 10px;">
                    <button type="button" onclick="removeManualPart(this)" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            `;
            manualPartsList.appendChild(newPartDiv);
        }
    } catch (error) {
        console.error('Error adding manual part:', error);
    }
}

function removeManualPart(button) {
    try {
        const partItem = button.closest('.manual-part-item');
        if (partItem) {
            partItem.remove();
            updatePartsSummary();
        }
    } catch (error) {
        console.error('Error removing manual part:', error);
    }
}

function updatePartSelection() {
    try {
        // This function will be called when checkboxes are checked/unchecked
        // Can be used for real-time validation or UI updates
        const checkboxes = document.querySelectorAll('input[name="required_parts_checkboxes[]"]:checked');
        console.log('Selected parts:', Array.from(checkboxes).map(cb => cb.value));
    } catch (error) {
        console.error('Error updating part selection:', error);
    }
}

function selectAllServiceTypes() {
    try {
        document.querySelectorAll('input[name="service_types[]"]').forEach(checkbox => {
            if (!checkbox.checked) {
                checkbox.checked = true;
                toggleServicePrice(checkbox.getAttribute('data-service-key'));
            }
        });
    } catch (error) {
        console.error('Error selecting all service types:', error);
    }
}

function selectAllRequiredParts() {
    try {
        // For checkbox format, select all checkboxes
        const checkboxes = document.querySelectorAll('input[name="required_parts_checkboxes[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updatePartsSummary();
    } catch (error) {
        console.error('Error selecting all required parts:', error);
    }
}

function deselectAllRequiredParts() {
    try {
        // For checkbox format, deselect all checkboxes
        const checkboxes = document.querySelectorAll('input[name="required_parts_checkboxes[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updatePartsSummary();
    } catch (error) {
        console.error('Error deselecting all required parts:', error);
    }
}

function clearRequiredParts() {
    try {
        // Clear auto-populated parts
        const checkboxes = document.querySelectorAll('input[name="required_parts_checkboxes[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        // Clear quantity and remarks fields for auto-populated parts
        const qtyFields = document.querySelectorAll('input[name="required_parts_qty[]"]');
        const remarksFields = document.querySelectorAll('input[name="required_parts_remarks[]"]');
        
        qtyFields.forEach(field => {
            field.value = '';
        });
        
        remarksFields.forEach(field => {
            field.value = '';
        });
        
        // Clear manual parts
        const manualPartsList = document.getElementById('manual-parts-list');
        if (manualPartsList) {
            manualPartsList.innerHTML = '';
        }
        
        updatePartsSummary();
    } catch (error) {
        console.error('Error clearing required parts:', error);
    }
}

function deselectAllServiceTypes() {
    try {
        document.querySelectorAll('input[name="service_types[]"]').forEach(checkbox => {
            if (checkbox.checked) {
                checkbox.checked = false;
                toggleServicePrice(checkbox.getAttribute('data-service-key'));
            }
        });
    } catch (error) {
        console.error('Error deselecting all service types:', error);
    }
}

function clearServiceTypes() {
    try {
        deselectAllServiceTypes();
        const serviceTypeOther = document.getElementById('service_type_other');
        if (serviceTypeOther) {
            serviceTypeOther.value = '';
        }
    } catch (error) {
        console.error('Error clearing service types:', error);
    }
}

function checkMechanicStatus() {
    try {
        const mechanicSelect = document.getElementById('mechanic_select');
        if (!mechanicSelect) {
            console.warn('Mechanic select element not found');
            return;
        }
        
        const selectedMechanicId = mechanicSelect.value;
        
        if (!selectedMechanicId) {
            // Reset override fields
            const staffOverride = document.getElementById('staff_override');
            const overrideReason = document.getElementById('override_reason');
            if (staffOverride) staffOverride.value = 'false';
            if (overrideReason) overrideReason.value = '';
            return;
        }
        
        // Check mechanic status via AJAX
        fetch('joborder.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax_action=check_mechanic_status&mechanic_id=${selectedMechanicId}`
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data && data.busy) {
                // Mechanic is busy, show confirmation dialog
                showMechanicOverrideDialog(data.job_order_id || 'Unknown');
            } else {
                // Mechanic is available, reset override fields
                const staffOverride = document.getElementById('staff_override');
                const overrideReason = document.getElementById('override_reason');
                if (staffOverride) staffOverride.value = 'false';
                if (overrideReason) overrideReason.value = '';
            }
        })
        .catch(error => {
            console.error('Error checking mechanic status:', error);
            // On error, allow proceeding
            const staffOverride = document.getElementById('staff_override');
            const overrideReason = document.getElementById('override_reason');
            if (staffOverride) staffOverride.value = 'false';
            if (overrideReason) overrideReason.value = '';
        });
    } catch (error) {
        console.error('Unexpected error in checkMechanicStatus:', error);
    }
}

function showMechanicOverrideDialog(jobOrderId) {
    const mechanicSelect = document.getElementById('mechanic_select');
    const mechanicName = mechanicSelect.options[mechanicSelect.selectedIndex].text;
    
    // Create modal dialog
    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
    `;
    
    const dialog = document.createElement('div');
    dialog.style.cssText = `
        background: white;
        padding: 30px;
        border-radius: 8px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    `;
    
    dialog.innerHTML = `
        <h3 style="color: #003d7a; margin-bottom: 20px;">Mechanic Assignment Alert</h3>
        <p style="margin-bottom: 15px;"><strong>${mechanicName}</strong> is currently busy with <strong>Job Order #${jobOrderId}</strong>.</p>
        <p style="margin-bottom: 25px;">Do you want to proceed or re-assign to another mechanic?</p>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Override Reason (optional):</label>
            <textarea id="override_reason_text" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-height: 60px; resize: vertical;" placeholder="Enter reason for override..."></textarea>
        </div>
        
        <div style="display: flex; gap: 15px; justify-content: flex-end;">
            <button type="button" onclick="reassignMechanic()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Re-assign</button>
            <button type="button" onclick="proceedWithOverride('${jobOrderId}')" style="padding: 10px 20px; background: #003d7a; color: white; border: none; border-radius: 4px; cursor: pointer;">Proceed</button>
        </div>
    `;
    
    modal.appendChild(dialog);
    document.body.appendChild(modal);
    
    // Store modal reference
    window.currentMechanicModal = modal;
}

function reassignMechanic() {
    // Remove modal
    if (window.currentMechanicModal) {
        document.body.removeChild(window.currentMechanicModal);
        window.currentMechanicModal = null;
    }
    
    // Reset mechanic selection
    const mechanicSelect = document.getElementById('mechanic_select');
    mechanicSelect.value = '';
    mechanicSelect.focus();
    
    // Reset override fields
    document.getElementById('staff_override').value = 'false';
    document.getElementById('override_reason').value = '';
}

function proceedWithOverride(jobOrderId) {
    const overrideReason = document.getElementById('override_reason_text').value || 'Staff proceeded with busy mechanic assignment';
    
    // Set override fields
    document.getElementById('staff_override').value = 'true';
    document.getElementById('override_reason').value = overrideReason;
    
    // Remove modal
    if (window.currentMechanicModal) {
        document.body.removeChild(window.currentMechanicModal);
        window.currentMechanicModal = null;
    }
    
    // Show confirmation
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #28a745;
        color: white;
        padding: 15px 20px;
        border-radius: 4px;
        z-index: 10001;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    `;
    notification.textContent = 'Override confirmed. Proceeding with busy mechanic assignment.';
    document.body.appendChild(notification);
    
    // Auto-remove notification after 3 seconds
    setTimeout(() => {
        if (document.body.contains(notification)) {
            document.body.removeChild(notification);
        }
    }, 3000);
}

function handlePaymentMethodChange() {
    const pm = document.getElementById('payment_method').value;
    const paymentFields = document.getElementById('payment_fields');

    // Hide all method-specific panels
    ['pm_cash','pm_card','pm_ewallet','pm_efuel','pm_credit'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    if (!pm) { paymentFields.style.display = 'none'; return; }

    paymentFields.style.display = 'block';
    updatePaymentSummary();

    const statusEl = document.getElementById('payment_status_display');

    switch (pm) {
        case 'Cash':
            document.getElementById('pm_cash').style.display = 'block';
            document.getElementById('sukli_group').style.display = 'none';
            setPaymentStatus('Pending', '#fff3cd', '#856404', '#ffc107');
            // Clear amount paid so staff must enter it
            const ap = document.getElementById('amount_paid');
            if (ap) ap.value = '';
            break;

        case 'Card':
            document.getElementById('pm_card').style.display = 'block';
            setPaymentStatus('Paid', '#d4edda', '#155724', '#c3e6cb');
            break;

        case 'E-Wallet':
            document.getElementById('pm_ewallet').style.display = 'block';
            setPaymentStatus('Paid', '#d4edda', '#155724', '#c3e6cb');
            break;

        case 'E-Fuel Card':
            document.getElementById('pm_efuel').style.display = 'block';
            setPaymentStatus('Paid', '#d4edda', '#155724', '#c3e6cb');
            break;

        case 'Credit':
            document.getElementById('pm_credit').style.display = 'block';
            setPaymentStatus('Pending Payment', '#f8d7da', '#721c24', '#f5c6cb');
            break;

        default:
            setPaymentStatus('Pending', '#fff3cd', '#856404', '#ffc107');
    }
}

function setPaymentStatus(text, bg, color, border) {
    const el = document.getElementById('payment_status_display');
    if (!el) return;
    el.textContent = text;
    el.style.background = bg;
    el.style.color      = color;
    el.style.borderColor = border;
}

function toggleServicePrice(serviceKey) {
    console.log("=== DEBUG toggleServicePrice called for:", serviceKey, "===");
    
    const checkbox = document.getElementById('service_' + serviceKey);
    const priceContainer = document.getElementById('price_container_' + serviceKey);
    
    console.log("Checkbox found:", !!checkbox);
    console.log("Price container found:", !!priceContainer);
    
    if (checkbox.checked) {
        priceContainer.style.display = 'block';
        
        // Reset to default price when checked
        const defaultPrice = checkbox.getAttribute('data-default-price');
        const priceInput = document.getElementById('price_' + serviceKey);
        if (priceInput) {
            priceInput.value = defaultPrice;
        }
        
        // Direct parts population for all service types
        const servicePartsMap = {
            'oil_change': { id: 'oil-change-parts', count: 10, name: 'Oil Change' },
            'tire_repair': { id: 'tire-repair-parts', count: 6, name: 'Tire Repair' },
            'calibration': { id: 'calibration-parts', count: 3, name: 'Calibration' },
            'general_maintenance': { id: 'general-maintenance-parts', count: 8, name: 'General Maintenance' },
            'engine_repair': { id: 'engine-repair-parts', count: 13, name: 'Engine Repair' },
            'brake_service': { id: 'brake-service-parts', count: 4, name: 'Brake Service' },
            'electrical': { id: 'electrical-parts', count: 3, name: 'Electrical' },
            'air_conditioning': { id: 'air-conditioning-parts', count: 4, name: 'Air Conditioning' },
            'transmission_service': { id: 'transmission-service-parts', count: 4, name: 'Transmission Service' },
            'suspension_repair': { id: 'suspension-repair-parts', count: 2, name: 'Suspension Repair' },
            'wheel_alignment': { id: 'wheel-alignment-parts', count: 3, name: 'Wheel Alignment' },
            'battery_replacement': { id: 'battery-replacement-parts', count: 2, name: 'Battery Replacement' },
            'diagnostic_check': { id: 'diagnostic-check-parts', count: 2, name: 'Diagnostic Check' },
            'detailing_cleaning': { id: 'detailing-cleaning-parts', count: 10, name: 'Detailing / Cleaning' }
        };

        // ---- Handle "Other" ---- shows in the parts container like all other services ----
        if (serviceKey === 'other') {
            console.log("=== DEBUG: Handling 'other' service ===");
            const inlineSection = document.getElementById('other-inline-section');
            const placeholder   = document.getElementById('parts-placeholder');
            const isManualFlag  = document.getElementById('is_manual_service');

            console.log("inlineSection found:", !!inlineSection);
            console.log("placeholder found:", !!placeholder);
            console.log("isManualFlag found:", !!isManualFlag);

            if (placeholder) {
                placeholder.style.setProperty('display', 'none', 'important');
                console.log("Placeholder hidden");
            }
            if (inlineSection) {
                inlineSection.style.setProperty('display', 'block', 'important');
                inlineSection.style.visibility = 'visible';
                inlineSection.removeAttribute('hidden');
                console.log("Other inline section shown");
            }
            if (isManualFlag) isManualFlag.value = '1';

            // Focus the custom service name field
            const nameField = document.getElementById('service_type_other');
            if (nameField && !nameField.value.trim()) setTimeout(() => nameField.focus(), 150);

            // Update indicator
            const textEl = document.getElementById('auto-populate-text');
            if (textEl) textEl.innerHTML = '<i class="fas fa-edit" style="color:#f59e0b"></i> Manual entry — type service name and add parts below';

            // Set validation flag for backend processing
            const validationRequired = document.getElementById('manual_validation_required');
            if (validationRequired) validationRequired.value = '1';

            updatePaymentSummary();
            return;
        }
        
        if (servicePartsMap[serviceKey]) {
            const serviceInfo = servicePartsMap[serviceKey];
            console.log(serviceInfo.name + ' selected, showing parts...');
            
            // Hide placeholder
            const placeholder = document.getElementById('parts-placeholder');
            if (placeholder) {
                placeholder.style.display = 'none';
            }
            
            // Show parts for the current service (do NOT hide other services' parts)
            const serviceParts = document.getElementById(serviceInfo.id);
            if (serviceParts) {
                serviceParts.style.display = 'block';
                serviceParts.style.visibility = 'visible';
                serviceParts.style.opacity = '1';
                serviceParts.style.height = 'auto';
                serviceParts.style.overflow = 'visible';
                serviceParts.classList.add('active');
                serviceParts.classList.remove('hidden');
                serviceParts.removeAttribute('hidden');
                // Tag each checkbox with its service key for audit trail
                serviceParts.querySelectorAll('input[name="required_parts_checkboxes[]"]').forEach(cb => {
                    cb.setAttribute('data-service', serviceKey);
                });
            } else {
                console.error("Parts container not found:", serviceInfo.id);
            }
            
            // Update indicator to list all currently checked services
            const checkedServices = Object.keys(servicePartsMap).filter(key => {
                const cb = document.getElementById('service_' + key);
                return cb && cb.checked;
            });
            const totalParts = checkedServices.reduce((sum, key) => sum + (servicePartsMap[key].count || 0), 0);
            const serviceNames = checkedServices.map(key => servicePartsMap[key].name).join(', ');
            const textElement = document.getElementById('auto-populate-text');
            if (textElement) {
                textElement.innerHTML = '<i class="fas fa-check-circle" style="color: #28a745;"></i> ' + totalParts + ' parts auto-populated for ' + serviceNames;
            }
        } else {
            console.error("Service key not found in servicePartsMap:", serviceKey);
        }
    } else {
        priceContainer.style.display = 'none';
        // Clear error when unchecked
        const errorSpan = document.getElementById('price_error_' + serviceKey);
        if (errorSpan) {
            errorSpan.textContent = '';
        }

        // ── Hide "Other" inline section when unchecked ──
        if (serviceKey === 'other') {
            const inlineSection = document.getElementById('other-inline-section');
            const isManualFlag  = document.getElementById('is_manual_service');
            if (inlineSection) {
                inlineSection.style.setProperty('display', 'none', 'important');
            }
            if (isManualFlag) isManualFlag.value = '0';
            // Clear fields
            const nameField = document.getElementById('service_type_other');
            if (nameField) { nameField.value = ''; nameField.style.borderColor = '#f59e0b'; }
            const otherList = document.getElementById('other-manual-parts-list');
            if (otherList) otherList.innerHTML = '';
            
            // Reset validation flag
            const validationRequired = document.getElementById('manual_validation_required');
            if (validationRequired) validationRequired.value = '0';
            
            // Show placeholder if no other services checked
            const anyOtherChecked = document.querySelectorAll('input[name="service_types[]"]:checked').length > 0;
            if (!anyOtherChecked) {
                const ph = document.getElementById('parts-placeholder');
                if (ph) ph.style.removeProperty('display');
            }
            updatePartsSummary();
            updatePaymentSummary();
            return;
        }
        
        // Hide parts when unchecked
        const servicePartsMap = {
            'oil_change': { id: 'oil-change-parts', name: 'Oil Change' },
            'tire_repair': { id: 'tire-repair-parts', name: 'Tire Repair' },
            'calibration': { id: 'calibration-parts', name: 'Calibration' },
            'general_maintenance': { id: 'general-maintenance-parts', name: 'General Maintenance' },
            'engine_repair': { id: 'engine-repair-parts', name: 'Engine Repair' },
            'brake_service': { id: 'brake-service-parts', name: 'Brake Service' },
            'electrical': { id: 'electrical-parts', name: 'Electrical' },
            'air_conditioning': { id: 'air-conditioning-parts', name: 'Air Conditioning' },
            'transmission_service': { id: 'transmission-service-parts', name: 'Transmission Service' },
            'suspension_repair': { id: 'suspension-repair-parts', name: 'Suspension Repair' },
            'wheel_alignment': { id: 'wheel-alignment-parts', name: 'Wheel Alignment' },
            'battery_replacement': { id: 'battery-replacement-parts', name: 'Battery Replacement' },
            'diagnostic_check': { id: 'diagnostic-check-parts', name: 'Diagnostic Check' },
            'detailing_cleaning': { id: 'detailing-cleaning-parts', name: 'Detailing / Cleaning' }
        };
        
        if (servicePartsMap[serviceKey]) {
            const serviceInfo = servicePartsMap[serviceKey];
            
            // Hide specific service parts
            const serviceParts = document.getElementById(serviceInfo.id);
            if (serviceParts) {
                serviceParts.style.display = 'none';
                serviceParts.classList.remove('active');
            }
            
            // Check if any other service types are still checked
            let anyServiceChecked = false;
            Object.keys(servicePartsMap).forEach(key => {
                const checkbox = document.getElementById('service_' + key);
                if (checkbox && checkbox.checked) {
                    anyServiceChecked = true;
                }
            });
            
            // Show placeholder only if no services are checked
            if (!anyServiceChecked) {
                const placeholder = document.getElementById('parts-placeholder');
                if (placeholder) {
                    placeholder.style.display = 'block';
                }
                
                // Update indicator
                const textElement = document.getElementById('auto-populate-text');
                if (textElement) {
                    textElement.innerHTML = 'Select service types above to auto-populate parts';
                }
            }
        }
        
        removeServiceParts(serviceKey);
    }
    
    updatePaymentSummary();
}

function autoPopulateServiceParts(serviceKey) {
    // Only handle parts showing if they exist in HTML
    const servicePartsMap = {
        'oil_change': { id: 'oil-change-parts' },
        'tire_repair': { id: 'tire-repair-parts' },
        'calibration': { id: 'calibration-parts' },
        'general_maintenance': { id: 'general-maintenance-parts' },
        'engine_repair': { id: 'engine-repair-parts' },
        'brake_service': { id: 'brake-service-parts' },
        'electrical': { id: 'electrical-parts' },
        'air_conditioning': { id: 'air-conditioning-parts' },
        'transmission_service': { id: 'transmission-service-parts' },
        'suspension_repair': { id: 'suspension-repair-parts' },
        'wheel_alignment': { id: 'wheel-alignment-parts' },
        'battery_replacement': { id: 'battery-replacement-parts' },
        'diagnostic_check': { id: 'diagnostic-check-parts' },
        'detailing_cleaning': { id: 'detailing-cleaning-parts' }
    };
    
    if (servicePartsMap[serviceKey]) {
        const partsContainer = document.getElementById(servicePartsMap[serviceKey].id);
        if (partsContainer) {
            partsContainer.style.display = 'block';
            partsContainer.style.visibility = 'visible';
            partsContainer.style.opacity = '1';
            partsContainer.classList.add('active');
            partsContainer.classList.remove('hidden');
        }
    }
}

function removeServiceParts(serviceKey) {
    const servicePartsMap = {
        'oil_change': { id: 'oil-change-parts' },
        'tire_repair': { id: 'tire-repair-parts' },
        'calibration': { id: 'calibration-parts' },
        'general_maintenance': { id: 'general-maintenance-parts' },
        'engine_repair': { id: 'engine-repair-parts' },
        'brake_service': { id: 'brake-service-parts' },
        'electrical': { id: 'electrical-parts' },
        'air_conditioning': { id: 'air-conditioning-parts' },
        'transmission_service': { id: 'transmission-service-parts' },
        'suspension_repair': { id: 'suspension-repair-parts' },
        'wheel_alignment': { id: 'wheel-alignment-parts' },
        'battery_replacement': { id: 'battery-replacement-parts' },
        'diagnostic_check': { id: 'diagnostic-check-parts' },
        'detailing_cleaning': { id: 'detailing-cleaning-parts' }
    };
    
    if (servicePartsMap[serviceKey]) {
        const partsContainer = document.getElementById(servicePartsMap[serviceKey].id);
        if (partsContainer) {
            partsContainer.style.display = 'none';
        }
    }
}

function validateServicePrice(serviceKey) {
    const priceInput = document.getElementById('price_' + serviceKey);
    const errorSpan = document.getElementById('price_error_' + serviceKey);
    const checkbox = document.getElementById('service_' + serviceKey);
    
    if(!priceInput || !checkbox) return true;

    const minPrice = parseFloat(checkbox.getAttribute('data-min-price'));
    const maxPrice = parseFloat(checkbox.getAttribute('data-max-price'));
    const enteredPrice = parseFloat(priceInput.value);
    
    if (errorSpan) {
        errorSpan.style.display = 'none';
        errorSpan.textContent = '';
    }
    priceInput.style.borderColor = '#ced4da';
    
    if (priceInput.value.trim() === '') {
        if(errorSpan) {
            errorSpan.textContent = 'Please enter a price';
            errorSpan.style.display = 'block';
        }
        priceInput.style.borderColor = '#dc3545';
        return false;
    }
    
    if (isNaN(enteredPrice)) return false;
    if (enteredPrice < minPrice || enteredPrice > maxPrice) {
        priceInput.style.borderColor = '#dc3545';
        return false;
    }
    
    priceInput.style.borderColor = '#28a745';
    return true;
}

function updatePaymentSummary() {
    try {
        // ── Labor cost = sum of all selected service prices ───────────────────
        let laborCost = 0;
        document.querySelectorAll('input[name="service_types[]"]:checked').forEach(cb => {
            const key = cb.getAttribute('data-service-key');
            if (key === 'other') {
                const v = parseFloat(document.getElementById('price_other')?.value || 0);
                if (!isNaN(v)) laborCost += v;
            } else {
                const inp = document.getElementById('price_' + key);
                if (inp && inp.value) {
                    const v = parseFloat(inp.value);
                    if (!isNaN(v)) laborCost += v;
                }
            }
        });

        // ── Parts cost = sum of unit_price × qty for checked parts ────────────
        let partsCost = 0;
        document.querySelectorAll('.service-parts.active input[name="required_parts_checkboxes[]"]:checked').forEach(cb => {
            const row   = cb.closest('.part-item');
            if (!row) return;
            // Try data-price first, then fall back to reading the price span text
            let price = parseFloat(cb.getAttribute('data-price') || 0);
            if (!price || isNaN(price)) {
                // Find the price span in the label (contains ₱ symbol)
                const label = row.querySelector('label');
                if (label) {
                    const spans = label.querySelectorAll('span');
                    spans.forEach(span => {
                        const txt = span.textContent.trim();
                        if (txt.startsWith('₱')) {
                            const parsed = parseFloat(txt.replace('₱', '').replace(/,/g, ''));
                            if (!isNaN(parsed) && parsed > 0) price = parsed;
                        }
                    });
                }
            }
            const qtyEl = row.querySelector('input[name="required_parts_qty[]"]');
            const qty   = qtyEl ? (parseInt(qtyEl.value) || 1) : 1;
            partsCost  += price * qty;
        });
        // Also count manual parts with price
        document.querySelectorAll('input[name="manual_parts_name[]"]').forEach((inp, i) => {
            const row   = inp.closest('div');
            if (!row) return;
            const priceEl = row.querySelector('input[name="manual_parts_price[]"]');
            const qtyEl   = row.querySelector('input[name="manual_parts_qty[]"]');
            if (priceEl) {
                const price = parseFloat(priceEl.value || 0);
                const qty   = qtyEl ? (parseInt(qtyEl.value) || 1) : 1;
                partsCost  += price * qty;
            }
        });

        const grandTotal = laborCost + partsCost;

        // ── Update display ────────────────────────────────────────────────────
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val.toFixed(2); };
        set('labor_cost_display',  laborCost);
        set('parts_cost_display',  partsCost);
        set('total_amount_display', grandTotal);

        // ── Sync hidden inputs ────────────────────────────────────────────────
        const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val.toFixed(2); };
        setVal('labor_cost_input',  laborCost);
        setVal('parts_cost_input',  partsCost);
        setVal('total_amount_input', grandTotal);

        // Legacy: keep service_cost_display in sync if it exists
        const scd = document.getElementById('service_cost_display');
        if (scd) scd.textContent = laborCost.toFixed(2);

        recalcPayment();
    } catch (e) {
        console.error('updatePaymentSummary error:', e);
    }
}

function recalcPayment() {
    const pm = document.getElementById('payment_method')?.value;
    const total = parseFloat(document.getElementById('total_amount_input')?.value || 0);

    if (pm === 'Cash') {
        const tendered = parseFloat(document.getElementById('amount_paid')?.value || 0);
        const sukliGroup = document.getElementById('sukli_group');
        const sukliDisp  = document.getElementById('sukli_display');
        const sukliInput = document.getElementById('sukli_input');

        if (tendered > 0 && total > 0) {
            const change = tendered - total;
            if (change >= 0) {
                if (sukliGroup) sukliGroup.style.display = 'block';
                if (sukliDisp)  sukliDisp.textContent = change.toFixed(2);
                if (sukliInput) sukliInput.value = change.toFixed(2);
                setPaymentStatus('Paid', '#d4edda', '#155724', '#c3e6cb');
            } else {
                if (sukliGroup) sukliGroup.style.display = 'none';
                setPaymentStatus('Insufficient — ₱' + Math.abs(change).toFixed(2) + ' short', '#f8d7da', '#721c24', '#f5c6cb');
            }
        } else {
            if (sukliGroup) sukliGroup.style.display = 'none';
            setPaymentStatus('Pending', '#fff3cd', '#856404', '#ffc107');
        }
    }
}

// Keep old name as alias so other callers don't break
function calculateSukli() { recalcPayment(); }

function viewJobOrderReceipt(jobOrderId) {
    const url = '../backend/job_order_receipt.php?action=print&job_order_id=' + encodeURIComponent(jobOrderId);
    const w = window.open(url, 'jo_receipt_' + jobOrderId,
        'width=480,height=720,scrollbars=yes,resizable=yes,toolbar=no,menubar=no');
    if (w) w.focus();
}

function switchTrackerTab(tabName) {
    const trackerContents = document.querySelectorAll('.tracker-tab-content');
    trackerContents.forEach(content => content.classList.remove('active'));
    const trackerButtons = document.querySelectorAll('.tracker-tab-btn');
    trackerButtons.forEach(btn => btn.classList.remove('active'));
    
    const selectedContent = document.getElementById(tabName + '-content');
    if (selectedContent) selectedContent.classList.add('active');
    
    const activeButton = document.querySelector(`[data-tab="${tabName}"]`);
    if (activeButton) activeButton.classList.add('active');
    
    // Save the selected tracker tab
    sessionStorage.setItem('activeTrackerTab', tabName);
}

function validateJobOrder(jobId, action) {
    const actionText = action === 'approve' ? 'approve' : 'reject';
    if (!confirm(`Are you sure you want to ${actionText} this job order?`)) return;
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'joborder.php';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden'; actionInput.name = 'action'; actionInput.value = 'validate_job_order';
    form.appendChild(actionInput);
    
    const jobIdInput = document.createElement('input');
    jobIdInput.type = 'hidden'; jobIdInput.name = 'job_id'; jobIdInput.value = jobId;
    form.appendChild(jobIdInput);
    
    const validationActionInput = document.createElement('input');
    validationActionInput.type = 'hidden'; validationActionInput.name = 'validation_action'; validationActionInput.value = action;
    form.appendChild(validationActionInput);

    // Preserve current tracker tab across the redirect
    const currentTrackerTab = sessionStorage.getItem('activeTrackerTab') || 'pending-validation';
    const tabInput = document.createElement('input');
    tabInput.type  = 'hidden';
    tabInput.name  = 'tracker_tab';
    tabInput.value = currentTrackerTab;
    form.appendChild(tabInput);
    
    document.body.appendChild(form);
    form.submit();
}

function viewJobOrderDetails(jobId) {
    // Show modal if exists
    console.log("Viewing job details for", jobId);
}

function closeJobDetailsModal() {
    const modal = document.getElementById('jobDetailsModal');
    if(modal) modal.style.display = 'none';
}

function showDatabaseError() {
    console.error("Database error fetching parts");
}

function selectAllRequiredParts() {
    document.querySelectorAll('input[name="required_parts_checkboxes[]"]').forEach(cb => cb.checked = true);
    updatePaymentSummary();
}

function deselectAllRequiredParts() {
    document.querySelectorAll('input[name="required_parts_checkboxes[]"]').forEach(cb => cb.checked = false);
    updatePaymentSummary();
}

function clearRequiredParts() {
    deselectAllRequiredParts();
}

function handleCreditCustomerChange() {}

// Fix parts display on load
document.addEventListener('DOMContentLoaded', function() {
    const serviceTypeCheckboxes = document.querySelectorAll('input[name="service_types[]"]:checked');
    serviceTypeCheckboxes.forEach(checkbox => {
        const serviceKey = checkbox.getAttribute('data-service-key');
        if(serviceKey) {
            autoPopulateServiceParts(serviceKey);
        }
    });
});

// Inject hidden service-key fields for each checked part before form submit
function injectPartsServiceData() {
    const form = document.querySelector('form[action="joborder.php"]');
    if (!form) return true;

    // ── 1. Validate: at least one service type must be selected ──────────────
    const checkedServices = document.querySelectorAll('input[name="service_types[]"]:checked');
    if (checkedServices.length === 0) {
        alert('Please select at least one service type.');
        const serviceContainer = document.getElementById('service-types-container');
        if (serviceContainer) serviceContainer.style.borderColor = '#dc3545';
        return false;
    }

    // ── 2. Validate: "Other" service requires a custom name ──────────────────
    const otherCb = document.getElementById('service_other');
    if (otherCb && otherCb.checked) {
        const nameField = document.getElementById('service_type_other');
        const errEl     = document.getElementById('other-service-name-error');
        if (!nameField || !nameField.value.trim()) {
            if (errEl) errEl.style.display = 'block';
            if (nameField) { nameField.style.borderColor = '#dc3545'; nameField.focus(); }
            return false;
        }
        if (errEl) errEl.style.display = 'none';
    }

    // ── 3. Inject service-key and price hidden fields for auto-populated parts ─
    form.querySelectorAll('input[name="required_parts_service[]"]').forEach(el => el.remove());
    form.querySelectorAll('input[name="required_parts_price[]"]').forEach(el => el.remove());
    document.querySelectorAll('.service-parts.active input[name="required_parts_checkboxes[]"]:checked').forEach(cb => {
        // service key
        const hiddenSvc = document.createElement('input');
        hiddenSvc.type  = 'hidden';
        hiddenSvc.name  = 'required_parts_service[]';
        hiddenSvc.value = cb.getAttribute('data-service') || 'general';
        form.appendChild(hiddenSvc);

        // unit price — read from data-price or from the ₱ span in the label
        let price = parseFloat(cb.getAttribute('data-price') || 0);
        if (!price || isNaN(price)) {
            const row = cb.closest('.part-item');
            if (row) {
                row.querySelectorAll('label span').forEach(span => {
                    const txt = span.textContent.trim();
                    if (txt.startsWith('₱')) {
                        const parsed = parseFloat(txt.replace('₱', '').replace(/,/g, ''));
                        if (!isNaN(parsed) && parsed > 0) price = parsed;
                    }
                });
            }
        }
        const hiddenPrice = document.createElement('input');
        hiddenPrice.type  = 'hidden';
        hiddenPrice.name  = 'required_parts_price[]';
        hiddenPrice.value = price || 0;
        form.appendChild(hiddenPrice);
    });

    return true;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
