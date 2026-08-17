<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

header('Content-Type: application/json');

// Start session for user authentication
session_start();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    // Validate required fields
    $required_fields = ['transaction_id', 'items', 'payment_method', 'customer_name', 'staff_id', 'timestamp', 'total_amount'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate user is logged in and has proper role
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        throw new Exception('User not authenticated');
    }
    
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    
    // Check if user has staff role
    if (!in_array($user_role, ['staff', 'cashier', 'pump_attendant'])) {
        throw new Exception('Unauthorized access');
    }
    
    // Get station ID
    $station_id = user_station_id();
    if (!$station_id) {
        throw new Exception('Station not found');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Insert main transaction record - use a simpler approach to avoid schema issues
        $stmt = $pdo->prepare("
            INSERT INTO sales (
                transaction_id, 
                station_id, 
                staff_id, 
                customer_name, 
                payment_method, 
                total_amount, 
                transaction_date, 
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_validation', NOW())
        ");
        
        $stmt->execute([
            $input['transaction_id'],
            $station_id,
            $input['staff_id'],
            $input['customer_name'],
            $input['payment_method'],
            $input['total_amount'],
            $input['timestamp']
        ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // Insert sale items - simplified approach
        foreach ($input['items'] as $item) {
            try {
                $itemStmt = $pdo->prepare("
                    INSERT INTO sale_items (
                        sale_id,
                        product_id,
                        sku,
                        product_name,
                        quantity,
                        unit_price,
                        subtotal,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $itemStmt->execute([
                    $sale_id,
                    $item['productId'] ?? null,
                    $item['sku'],
                    $item['productName'],
                    $item['quantity'],
                    $item['unitPrice'],
                    $item['subtotal']
                ]);
                
                // Update inventory stock levels via Global Movement Engine
                if (!empty($item['productId'])) {
                    try {
                        record_merchandise_sale_movement(
                            $pdo,
                            $station_id,
                            (int)$item['productId'],
                            (float)$item['quantity'],
                            $input['transaction_id'],
                            (int)($me['id'] ?? 0)
                        );
                    } catch (Exception $stockError) {
                        error_log("Stock update failed: " . $stockError->getMessage());
                    }
                }
            } catch (Exception $itemError) {

                // Log item insertion error but continue with other items
                error_log("Item insertion failed: " . $itemError->getMessage());
            }
        }
        
        // Handle credit customer if applicable - simplified approach
        if (!empty($input['credit_customer_id']) && $input['payment_method'] === 'Account Receivable') {
            $custCheck = $pdo->prepare("SELECT status FROM customers WHERE id = ? LIMIT 1");
            $custCheck->execute([$input['credit_customer_id']]);
            $custStatus = $custCheck->fetchColumn();
            if ($custStatus === 'locked') {
                throw new Exception("Transaction blocked: Customer account is locked.");
            }
            if ($custStatus === 'inactive') {
                throw new Exception("Transaction blocked: Customer account is inactive.");
            }
            try {
                $creditStmt = $pdo->prepare("
                    INSERT INTO accounts_receivable (
                        customer_id,
                        sale_id,
                        transaction_id,
                        amount,
                        due_date,
                        status,
                        station_id,
                        created_at
                    ) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), 'pending', ?, NOW())
                ");
                
                $creditStmt->execute([
                    $input['credit_customer_id'],
                    $sale_id,
                    $input['transaction_id'],
                    $input['total_amount'],
                    $station_id
                ]);
                
                // Update customer balance
                try {
                    $updateBalanceStmt = $pdo->prepare("
                        UPDATE customers 
                        SET balance = balance + ? 
                        WHERE id = ? AND station_id = ?
                    ");
                    $updateBalanceStmt->execute([
                        $input['total_amount'],
                        $input['credit_customer_id'],
                        $station_id
                    ]);
                    
                    // Also write to customer_credit_transactions
                    try {
                        // Fetch updated balance
                        $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
                        $bal_stmt->execute([$input['credit_customer_id']]);
                        $new_bal = (float)$bal_stmt->fetchColumn();
                        
                        $cct_stmt = $pdo->prepare("
                            INSERT INTO customer_credit_transactions (
                                customer_id, transaction_id, transaction_type, amount, 
                                running_balance, description, station_id, created_by, created_at
                            ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                        ");
                        $cct_stmt->execute([
                            $input['credit_customer_id'],
                            $input['transaction_id'],
                            $input['total_amount'],
                            $new_bal,
                            "Merchandise Sale (Credit) - Ref: " . $input['transaction_id'],
                            $station_id,
                            $input['staff_id']
                        ]);
                    } catch (Exception $ccError) {
                        error_log("Error inserting into customer_credit_transactions: " . $ccError->getMessage());
                    }
                } catch (Exception $balanceError) {
                    error_log("Customer balance update failed: " . $balanceError->getMessage());
                }
            } catch (Exception $creditError) {
                error_log("Credit customer handling failed: " . $creditError->getMessage());
            }
        }
        
        // Log to audit trail - simplified approach
        try {
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
                'transaction_id' => $input['transaction_id'],
                'customer_name' => $input['customer_name'],
                'total_amount' => $input['total_amount'],
                'payment_method' => $input['payment_method'],
                'items_count' => count($input['items']),
                'items' => $input['items']
            ]);
            
            $auditStmt->execute([
                $input['staff_id'],
                $station_id,
                'Merchandise transaction completed',
                $auditDetails,
                $input['transaction_id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $auditError) {
            error_log("Audit trail logging failed: " . $auditError->getMessage());
            // Continue even if audit fails
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction completed successfully',
            'transaction_id' => $input['transaction_id'],
            'sale_id' => $sale_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
