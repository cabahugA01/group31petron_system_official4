<?php
// API endpoint for staff to submit transactions
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();
header('Content-Type: application/json');

$me = current_user();
$role = role_key($me['role'] ?? '');

// Restrict to staff only
if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$station_id = user_station_id();
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['transaction_data'])) {
    echo json_encode(['success' => false, 'message' => 'Transaction data required']);
    exit;
}

$transaction_data = $data['transaction_data'];

try {
    $pdo = getPDO();
    
    // Ensure status column exists in sales table
    $pdo->exec("ALTER TABLE sales ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Completed'");
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Insert into sales table
    $stmt = $pdo->prepare("INSERT INTO sales (station_id, user_id, customer_id, payment_method, status, created_at) VALUES (?, ?, ?, ?, 'Pending Validation', NOW())");
    $stmt->execute([
        $station_id,
        $me['id'],
        $transaction_data['customer_id'] ?? null,
        $transaction_data['payment_method'],
    ]);
    $sale_id = $pdo->lastInsertId();
    
    // Insert into sale_items table
    $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, name, quantity, unit_price, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $sale_id,
        $transaction_data['product_id'] ?? null,
        $transaction_data['product_name'],
        $transaction_data['quantity'],
        $transaction_data['unit_price'],
        $transaction_data['total_amount']
    ]);
    
    // Log submission
    $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Transaction Submitted', ?, NOW())");
    $stmt->execute([$station_id, $me['id'], "Transaction #$sale_id submitted by staff for manager validation"]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Transaction submitted successfully. Awaiting Manager validation.',
        'sale_id' => $sale_id
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
