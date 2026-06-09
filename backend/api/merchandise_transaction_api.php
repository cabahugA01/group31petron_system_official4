<?php
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../../backend/lib.php';

header('Content-Type: application/json');

// Start session for user authentication
session_start();

try {
    // Get request method and endpoint
    $method = $_SERVER['REQUEST_METHOD'];
    $endpoint = $_GET['action'] ?? '';

    // Validate user is logged in and has proper role
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('User not authenticated');
    }

    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    // Get station ID
    $station_id = user_station_id();
    if (!$station_id) {
        throw new Exception('Station not found');
    }

    switch ($endpoint) {
        case 'submit_merchandise_transaction':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            // Check if user has staff role
            if (!in_array($user_role, ['staff', 'cashier', 'pump_attendant'])) {
                throw new Exception('Unauthorized access');
            }
            
            submitMerchandiseTransaction($pdo, $station_id, $user_id);
            break;
            
        case 'get_pending_transactions':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            
            // Check if user has manager role
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                throw new Exception('Unauthorized access');
            }
            
            getPendingTransactions($pdo, $station_id);
            break;
            
        case 'validate_transaction':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            // Check if user has manager role
            if (!in_array($user_role, ['manager', 'admin', 'superadmin'])) {
                throw new Exception('Unauthorized access');
            }
            
            validateTransaction($pdo, $station_id, $user_id);
            break;
            
        case 'get_active_shift':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }
            
            getActiveShift($pdo, $station_id, $user_id);
            break;
            
        default:
            throw new Exception('Invalid endpoint');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function submitMerchandiseTransaction($pdo, $station_id, $staff_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    // Validate required fields
    $required_fields = ['items', 'payment_method', 'customer_name'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Get active shift
    $active_shift = getActiveShiftData($pdo, $station_id, $staff_id);
    $shift_id = $active_shift ? $active_shift['id'] : null;
    $shift_status = $active_shift ? 'active' : 'no_active_shift';
    
    // Generate transaction ID
    $transaction_id = generateTransactionId($station_id);
    
    // Calculate total amount
    $total_amount = 0;
    foreach ($input['items'] as $item) {
        $total_amount += ($item['quantity'] * $item['unit_price']);
    }
    
    $pdo->beginTransaction();
    
    try {
        // Insert into pending_merchandise_transactions table
        $stmt = $pdo->prepare("
            INSERT INTO pending_merchandise_transactions (
                transaction_id,
                station_id,
                staff_id,
                shift_id,
                customer_name,
                payment_method,
                items,
                total_amount,
                validation_status,
                shift_status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Validation', ?, NOW())
        ");
        
        $stmt->execute([
            $transaction_id,
            $station_id,
            $staff_id,
            $shift_id,
            $input['customer_name'],
            $input['payment_method'],
            json_encode($input['items']),
            $total_amount,
            $shift_status
        ]);
        
        $pending_id = $pdo->lastInsertId();
        
        // Also save to sales table for transactions_oversight API compatibility
        $salesStmt = $pdo->prepare("
            INSERT INTO sales (
                transaction_id,
                station_id,
                user_id,
                customer,
                payment_method,
                total,
                transaction_date,
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_validation', NOW())
        ");
        
        $salesStmt->execute([
            $transaction_id,
            $station_id,
            $staff_id,
            $input['customer_name'],
            $input['payment_method'],
            $total_amount,
            date('Y-m-d H:i:s')
        ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // Insert sale items
        foreach ($input['items'] as $item) {
            $itemStmt = $pdo->prepare("
                INSERT INTO sale_items (
                    sale_id,
                    product_name,
                    quantity,
                    unit_price,
                    subtotal,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $itemStmt->execute([
                $sale_id,
                $item['name'] ?? $item['product_name'] ?? 'Unknown Product',
                $item['quantity'],
                $item['unit_price'],
                ($item['quantity'] * $item['unit_price'])
            ]);
        }
        
        // Log to audit trail
        $auditStmt = $pdo->prepare("
            INSERT INTO staff_audit_log (
                staff_id,
                station_id,
                action,
                details,
                reference_id,
                ip_address,
                user_agent,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $auditDetails = json_encode([
            'transaction_id' => $transaction_id,
            'customer_name' => $input['customer_name'],
            'total_amount' => $total_amount,
            'payment_method' => $input['payment_method'],
            'items_count' => count($input['items']),
            'shift_id' => $shift_id,
            'shift_status' => $shift_status,
            'items' => $input['items']
        ]);
        
        $auditStmt->execute([
            $staff_id,
            $station_id,
            'Merchandise transaction submitted for validation',
            $auditDetails,
            $transaction_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction submitted successfully',
            'transaction_id' => $transaction_id,
            'pending_id' => $pending_id,
            'shift_status' => $shift_status,
            'total_amount' => $total_amount
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getPendingTransactions($pdo, $station_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pmt.id,
                pmt.transaction_id,
                pmt.customer_name,
                pmt.payment_method,
                pmt.items,
                pmt.total_amount,
                pmt.validation_status,
                pmt.shift_status,
                pmt.created_at,
                pmt.staff_id,
                u.name as staff_name,
                pmt.shift_id,
                sh.start_time,
                sh.end_time
            FROM pending_merchandise_transactions pmt
            LEFT JOIN users u ON pmt.staff_id = u.id
            LEFT JOIN shifts sh ON pmt.shift_id = sh.id
            WHERE pmt.station_id = ? AND pmt.validation_status = 'Pending Validation'
            ORDER BY pmt.created_at DESC
        ");
        
        $stmt->execute([$station_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse items JSON for each transaction
        foreach ($transactions as &$transaction) {
            $transaction['items'] = json_decode($transaction['items'], true) ?: [];
            $transaction['items_count'] = count($transaction['items']);
        }
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching pending transactions: ' . $e->getMessage());
    }
}

function validateTransaction($pdo, $station_id, $manager_id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    $required_fields = ['pending_id', 'action', 'transaction_id'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $action = $input['action']; // approve, reject, adjust
    $pending_id = $input['pending_id'];
    $transaction_id = $input['transaction_id'];
    $remarks = $input['remarks'] ?? '';
    $adjustments = $input['adjustments'] ?? [];
    
    $pdo->beginTransaction();
    
    try {
        // Get pending transaction details
        $stmt = $pdo->prepare("
            SELECT * FROM pending_merchandise_transactions 
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$pending_id, $station_id]);
        $pending_transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pending_transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Update validation status
        $new_status = $action === 'approve' ? 'Approved' : ($action === 'reject' ? 'Rejected' : 'Adjusted');
        
        $stmt = $pdo->prepare("
            UPDATE pending_merchandise_transactions 
            SET validation_status = ?, 
                validated_by = ?, 
                validated_at = NOW(),
                validation_remarks = ?,
                adjustments = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $new_status,
            $manager_id,
            $remarks,
            json_encode($adjustments),
            $pending_id
        ]);
        
        // If approved or adjusted, move to main sales table
        if ($action === 'approve' || $action === 'adjust') {
            $items = json_decode($pending_transaction['items'], true);
            $final_items = $action === 'adjust' ? $adjustments : $items;
            
            // Calculate new total if adjusted
            $final_total = $pending_transaction['total_amount'];
            if ($action === 'adjust' && !empty($adjustments)) {
                $final_total = 0;
                foreach ($final_items as $item) {
                    $final_total += ($item['quantity'] * $item['unit_price']);
                }
            }
            
            // Insert into sales table
            $stmt = $pdo->prepare("
                INSERT INTO sales (
                    transaction_id,
                    station_id,
                    user_id,
                    customer_id,
                    customer,
                    payment_method,
                    total,
                    transaction_date,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Completed', NOW())
            ");
            
            $stmt->execute([
                $transaction_id,
                $station_id,
                $pending_transaction['staff_id'],
                null,
                $pending_transaction['customer_name'],
                $pending_transaction['payment_method'],
                $final_total,
                $pending_transaction['created_at']
            ]);
            
            $sale_id = $pdo->lastInsertId();
            
            // Insert sale items
            foreach ($final_items as $item) {
                $itemStmt = $pdo->prepare("
                    INSERT INTO sale_items (
                        sale_id,
                        product_id,
                        sku,
                        name,
                        quantity,
                        unit_price,
                        subtotal,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $itemStmt->execute([
                    $sale_id,
                    $item['product_id'] ?? null,
                    $item['sku'] ?? '',
                    $item['name'] ?? $item['productName'] ?? '',
                    $item['quantity'],
                    $item['unit_price'],
                    ($item['quantity'] * $item['unit_price'])
                ]);
            }

            // Deduct stock from station_inventory for each approved item
            foreach ($final_items as $item) {
                $product_id = $item['product_id'] ?? null;
                $qty        = $item['quantity'] ?? 0;

                if ($product_id && $qty > 0) {
                    $deductStmt = $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = GREATEST(stock_level - ?, 0),
                            last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ");
                    $deductStmt->execute([$qty, $station_id, $product_id]);
                }
            }
        }
        
        // Log validation action to audit trail
        $auditStmt = $pdo->prepare("
            INSERT INTO staff_audit_log (
                staff_id,
                station_id,
                action,
                details,
                reference_id,
                ip_address,
                user_agent,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $auditDetails = json_encode([
            'transaction_id' => $transaction_id,
            'action' => $action,
            'remarks' => $remarks,
            'original_total' => $pending_transaction['total_amount'],
            'adjustments' => $adjustments,
            'validated_by' => $manager_id
        ]);
        
        $auditStmt->execute([
            $manager_id,
            $station_id,
            "Merchandise transaction $action",
            $auditDetails,
            $transaction_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Transaction $new_status successfully",
            'transaction_id' => $transaction_id,
            'action' => $action,
            'new_status' => $new_status
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getActiveShift($pdo, $station_id, $staff_id) {
    $active_shift = getActiveShiftData($pdo, $station_id, $staff_id);
    
    echo json_encode([
        'success' => true,
        'active_shift' => $active_shift,
        'has_active_shift' => !empty($active_shift)
    ]);
}

function getActiveShiftData($pdo, $station_id, $staff_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT sh.*, u.name as staff_name
            FROM shifts sh
            LEFT JOIN users u ON sh.staff_id = u.id
            WHERE sh.station_id = ? 
            AND sh.staff_id = ? 
            AND sh.status = 'Active'
            AND sh.start_time <= NOW()
            AND (sh.end_time IS NULL OR sh.end_time >= NOW())
            ORDER BY sh.start_time DESC
            LIMIT 1
        ");
        
        $stmt->execute([$station_id, $staff_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting active shift: " . $e->getMessage());
        return null;
    }
}

function generateTransactionId($station_id) {
    $prefix = 'MERCH-' . date('Ymd') . '-';
    $sequence = 1;
    
    try {
        global $pdo;
        
        // Get last transaction ID for today
        $stmt = $pdo->prepare("
            SELECT transaction_id 
            FROM pending_merchandise_transactions 
            WHERE station_id = ? AND DATE(created_at) = CURDATE()
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$station_id]);
        $last_transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($last_transaction) {
            // Extract sequence number from last transaction ID
            $parts = explode('-', $last_transaction['transaction_id']);
            if (count($parts) >= 3) {
                $sequence = (int)end($parts) + 1;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error generating transaction ID: " . $e->getMessage());
    }
    
    return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}
?>
