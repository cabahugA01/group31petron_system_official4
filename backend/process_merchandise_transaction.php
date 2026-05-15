<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Only allow staff to process transactions
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    $_SESSION['error'] = 'Access denied. Staff role required.';
    header('Location: ../public/staff_transactions.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../public/staff_transactions.php');
    exit;
}

function ensure_pending_tables(PDO $pdo): void {
    // pending_merchandise_transactions table already created by schema.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_merchandise_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id VARCHAR(64) NOT NULL,
        station_id INT NOT NULL,
        staff_id INT NOT NULL,
        action_by INT NOT NULL,
        action_type VARCHAR(30) NOT NULL,
        old_status VARCHAR(30) NULL,
        new_status VARCHAR(30) NULL,
        remarks TEXT NULL,
        details JSON NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_transaction_action (transaction_id, action_type),
        INDEX idx_station_time (station_id, timestamp),
        INDEX idx_action_time (action_type, timestamp),
        FOREIGN KEY (transaction_id) REFERENCES pending_merchandise_transactions(transaction_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Validate required fields
$required_fields = ['item_sku', 'quantity', 'payment_method'];
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        $_SESSION['error'] = "Missing required field: $field";
        header('Location: ../public/staff_transactions.php');
        exit;
    }
}

try {
ensure_pending_tables($pdo);
    $pdo->beginTransaction();
    $allowed_payment_methods = ['Cash', 'Credit Card', 'Account Receivable'];

    if (!in_array($_POST['payment_method'], $allowed_payment_methods, true)) {
        throw new Exception('Invalid payment method selected.');
    }
    
    // Resolve the selected item by SKU first, then by product name.
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name,
            COALESCE(NULLIF(ip.sku, ''), ip.product_name) AS sku,
            ip.category,
            COALESCE(si.stock_level, 0) AS stock_level,
                        COALESCE(si.price, si.cost, ip.unit_cost, 0) AS unit_price
        FROM inventory_products ip
        LEFT JOIN station_inventory si
            ON si.station_id = ?
                     AND si.product_id = ip.id
        WHERE ip.category != 'Fuel'
          AND (LOWER(TRIM(ip.sku)) = LOWER(TRIM(?)) OR LOWER(TRIM(ip.product_name)) = LOWER(TRIM(?)))
        ORDER BY CASE WHEN LOWER(TRIM(ip.sku)) = LOWER(TRIM(?)) THEN 0 ELSE 1 END, ip.product_name
        LIMIT 1
    ");
    $stmt->execute([$station_id, $_POST['item_sku'], $_POST['item_sku'], $_POST['item_sku']]);
    $merch_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merch_data) {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name AS product_name,
                COALESCE(NULLIF(p.sku, ''), p.name) AS sku,
                COALESCE(pc.name, 'General') AS category,
                COALESCE(si.stock_level, 0) AS stock_level,
                COALESCE(si.price, p.price, si.cost, p.cost, 0) AS unit_price
            FROM products p
            LEFT JOIN product_categories pc
                ON pc.id = p.category_id
            LEFT JOIN station_inventory si
                ON si.station_id = ?
               AND si.product_id = p.id
            WHERE p.type_id = 2
              AND (LOWER(TRIM(p.sku)) = LOWER(TRIM(?)) OR LOWER(TRIM(p.name)) = LOWER(TRIM(?)))
            ORDER BY CASE WHEN LOWER(TRIM(p.sku)) = LOWER(TRIM(?)) THEN 0 ELSE 1 END, p.name
            LIMIT 1
        ");
        $stmt->execute([$station_id, $_POST['item_sku'], $_POST['item_sku'], $_POST['item_sku']]);
        $merch_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$merch_data) {
        throw new Exception('Invalid merchandise item selected.');
    }
    
    // Auto-pull unit price
    $unit_price = floatval($merch_data['unit_price']);
    $quantity = intval($_POST['quantity']);
    $total_amount = $quantity * $unit_price;
    
    // Validate computation
    if ($quantity <= 0) {
        throw new Exception('Invalid quantity: Quantity must be greater than 0.');
    }

    if ($unit_price <= 0) {
        throw new Exception('Invalid unit price for the selected item.');
    }
    
    if ($total_amount <= 0) {
        throw new Exception('Invalid computation: Total amount must be greater than 0.');
    }
    
    // Check inventory stock
    $available_stock = floatval($merch_data['stock_level'] ?? 0);
    if ($available_stock < $quantity) {
        throw new Exception('Insufficient stock. Available: ' . $available_stock . ' units.');
    }

    $resolved_item_sku = $merch_data['sku'] ?: $_POST['item_sku'];
    $resolved_product_name = $merch_data['product_name'] ?: $_POST['item_sku'];
    $customer_name = trim((string)($_POST['customer_name'] ?? ''));
    $credit_customer_id = trim((string)($_POST['credit_customer_id'] ?? ''));
    $ar_customer_reference = $credit_customer_id !== '' ? $credit_customer_id : null;
    
    // Generate transaction ID
    $transaction_id = 'MERCH_' . date('YmdHis') . '_' . $station_id . '_' . $me['id'];
    

    
    // Get additional form fields
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $validation_status = trim((string)($_POST['validation_status'] ?? 'Pending'));
    $shift_id = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
    $transaction_timestamp = trim((string)($_POST['transaction_timestamp'] ?? date('Y-m-d H:i:s')));

// === PENDING VALIDATION FLOW (Phase 2) ===
    // Serialize cart items as JSON for pending record
    $cart_items = [
        [
            'sku' => $resolved_item_sku,
            'name' => $resolved_product_name,
            'category' => $merch_data['category'] ?? 'General',
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $total_amount
        ]
    ];
    
    // Detect shift status
    $shift_status = $shift_id ? 'Active Shift' : 'No Active Shift';
    
    // Insert PENDING record (NO inventory/sales yet)
    $stmt = $pdo->prepare("
        INSERT INTO pending_merchandise_transactions (
            transaction_id, station_id, staff_id, shift_id, shift_status,
            customer_name, credit_customer_id, payment_method, items, total_amount,
            validation_status, transaction_timestamp
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Validation', ?)
    ");
    $stmt->execute([
        $transaction_id,
        $station_id,
        $me['id'],
        $shift_id,
        $shift_status,
        $customer_name ?: null,
        $credit_customer_id ?: null,
        $_POST['payment_method'],
        json_encode($cart_items),
        $total_amount,
        $transaction_timestamp
    ]);
    
    // Auto-audit submission
    $stmt = $pdo->prepare("
        INSERT INTO pending_merchandise_audit (
            transaction_id, station_id, staff_id, action_by, action_type, remarks
        ) VALUES (?, ?, ?, ?, 'submit', ?)
    ");
    $stmt->execute([
        $transaction_id,
        $station_id,
        $me['id'],
        $me['id'],
        $remarks
    ]);
    
    // === PENDING: NO INVENTORY/AR UPDATE UNTIL MANAGER APPROVES ===
    // DEFERRED: sale_items, station_inventory deduct, accounts_receivable
    
    // Create audit trail
    $audit_data = [
        'staff_id' => $me['id'],
        'staff_name' => $me['name'] ?? $me['username'],
        'transaction_id' => $transaction_id,
        'item_sku' => $resolved_item_sku,
        'quantity' => $quantity,
        'unit_price' => $unit_price,
        'total_amount' => $total_amount,
        'payment_method' => $_POST['payment_method'],
        'customer_name' => $customer_name,
        'station_id' => $station_id,
        'shift_id' => $shift_id,
        'transaction_timestamp' => $transaction_timestamp,
        'remarks' => $remarks,
        'validation_status' => $validation_status,
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => 'Merchandise Transaction Processed'
    ];
    
    // === AUDIT PENDING SUBMISSION (already auto-triggered in Phase 2 insert) ===
    // SKIP duplicate audit - pending_merchandise_audit handles it
    
    // === PENDING RECEIPT (watermarked "PENDING VALIDATION") ===
    $receipt_data = [
        'transaction_id' => $transaction_id . ' [PENDING]',
        'status' => 'Pending Manager Validation',
        'staff_id' => $me['id'],
        'staff_name' => $me['name'] ?? $me['username'],
        'shift_status' => $shift_status,
        'shift_id' => $shift_id,
        'transaction_timestamp' => $transaction_timestamp,
        'customer_name' => $customer_name ?: 'Walk-in Customer',
        'items' => $cart_items,
        'total_amount' => $total_amount,
        'payment_method' => $_POST['payment_method'],
        'station_id' => $station_id,
        'remarks' => $remarks . "\n\n*** PENDING MANAGER VALIDATION REQUIRED ***"
    ];
    
    $_SESSION['pending_receipt_data'] = $receipt_data;
    
    // Log activity
    if (function_exists('log_activity')) {
        log_activity($pdo, $me['id'], 'Merchandise Transaction', 
            "Processed merchandise transaction: {$transaction_id} - {$resolved_item_sku} - {$quantity}pcs - ₱{$total_amount}");
    }
    
    $pdo->commit();
    
    // Store receipt data in session for receipt generation
    $_SESSION['receipt_data'] = $receipt_data;
    $_SESSION['receipt_generated'] = true;
    
    $_SESSION['success'] = "✅ Transaction SUBMITTED for Manager Validation! TXN: {$transaction_id} | Items: {$quantity} × {$resolved_product_name} | ₱" . number_format($total_amount, 2) . " | Shift: {$shift_status}";
    
    // Store transaction_id for staff reference
    $_SESSION['last_pending_transaction'] = $transaction_id;
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = "❌ Error processing merchandise transaction: " . $e->getMessage();
}

header('Location: ../public/staff_transactions.php');
exit;
?>
