<?php
/**
 * Process Merchandise Only Transaction
 */
session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json');

try {
    $me = current_user();
    $station_id = user_station_id();
    
    // Validate required fields
    if (empty($_POST['customer_name'])) {
        throw new Exception('Customer name is required');
    }
    
    if (empty($_POST['items']) || !is_array($_POST['items'])) {
        throw new Exception('At least one product item is required');
    }
    
    $customer_name = trim($_POST['customer_name']);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $items = $_POST['items'];
    
    // Calculate totals
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += floatval($item['quantity']) * floatval($item['unit_price']);
    }
    
    $vat_rate = 0.12;
    $vat_amount = $subtotal * $vat_rate;
    $total_amount = $subtotal + $vat_amount;
    
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $balance_due = max(0, $total_amount - $amount_paid);
    $payment_status = ($balance_due <= 0.01) ? 'Paid' : (($amount_paid > 0) ? 'Partial' : 'Pending Payment');
    
    // Get shift info
    $shift_period = '';
    $shift_name = '';
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
    
    // Generate transaction ID
    $transaction_id = 'MT-' . $station_id . '-' . date('Ymd-His');
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Insert merchandise transaction
    $stmt = $pdo->prepare("
        INSERT INTO merchandise_transactions (
            transaction_id, customer_name, subtotal_amount, vat_amount, total_amount,
            amount_paid, balance_due, payment_status, payment_method,
            transaction_type, staff_id, station_id, shift_period, shift_name,
            transaction_date, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            'merchandise', 
            ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        $transaction_id, $customer_name, $subtotal, $vat_amount, $total_amount,
        $amount_paid, $balance_due, $payment_status, $payment_method,
        $me['id'], $station_id, $shift_period, $shift_name
    ]);
    
    $merch_txn_id = $pdo->lastInsertId();
    
    // Insert items
    $item_stmt = $pdo->prepare("
        INSERT INTO merchandise_transaction_items (
            transaction_id, product_id, product_name, quantity, unit_price, item_type
        ) VALUES (?, ?, ?, ?, ?, 'merchandise')
    ");
    
    foreach ($items as $item) {
        $item_stmt->execute([
            $merch_txn_id,
            $item['product_id'],
            $item['product_name'],
            $item['quantity'],
            $item['unit_price']
        ]);
    }
    
        $pdo->commit();

    notify_transaction_submission($pdo, (int)$station_id, [
        'transaction_id'    => $transaction_id,
        'transaction_db_id' => (int)$merch_txn_id,
        'transaction_type'  => 'merchandise',
        'total_amount'      => (float)$total_amount,
        'customer_name'     => $customer_name,
        'staff_name'        => $me['name'] ?? $me['username'] ?? 'Staff',
        'shift_period'      => $shift_period ?? $shift_name ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Merchandise transaction created successfully',
        'transaction_id' => $transaction_id,
        'merch_txn_id' => $merch_txn_id,
        'total_amount' => $total_amount
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
