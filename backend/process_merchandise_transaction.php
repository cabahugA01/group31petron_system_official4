<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'], true)) {
    $_SESSION['error'] = 'Access denied. Staff role required.';
    header('Location: ../public/staff_transactions.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../public/staff_transactions.php');
    exit;
}

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $columns[strtolower($row['Field'])] = true;
    }
    return $columns;
}

function ensure_official_merchandise_schema(PDO $pdo): void
{
    try {
        $columns = table_columns($pdo, 'merchandise_transactions');
        $add = [
            'shift_id' => 'INT NULL',
            'shift_period' => 'VARCHAR(50) NULL',
            'shift_name' => 'VARCHAR(100) NULL',
            'customer_name' => "VARCHAR(255) NOT NULL DEFAULT 'Walk-in Customer'",
            'customer_first_name' => 'VARCHAR(100) NULL',
            'customer_last_name' => 'VARCHAR(100) NULL',
            'credit_customer_id' => 'INT NULL',
            'payment_method' => "VARCHAR(50) NOT NULL DEFAULT 'Cash'",
            'subtotal_amount' => 'DECIMAL(10,2) NULL',
            'vat_amount' => 'DECIMAL(10,2) NULL',
            'amount_tendered' => 'DECIMAL(10,2) NULL',
            'change_amount' => 'DECIMAL(10,2) NULL',
            'card_reference' => 'VARCHAR(100) NULL',
            'card_type' => 'VARCHAR(50) NULL',
            'ewallet_reference' => 'VARCHAR(100) NULL',
            'ewallet_provider' => 'VARCHAR(50) NULL',
            'efuel_card_number' => 'VARCHAR(50) NULL',
            'remarks' => 'TEXT NULL',
            'staff_remarks' => 'TEXT NULL',
            'validation_status' => "VARCHAR(20) NOT NULL DEFAULT 'Official'",
            'payment_status' => "VARCHAR(30) NOT NULL DEFAULT 'Paid'",
            'workflow_status' => "VARCHAR(20) NOT NULL DEFAULT 'Completed'",
            'amount_paid' => 'DECIMAL(10,2) NULL',
            'balance_due' => 'DECIMAL(10,2) NULL',
            'inventory_deducted' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'transaction_type' => "VARCHAR(20) NOT NULL DEFAULT 'merchandise'",
            'updated_at' => 'DATETIME NULL',
        ];

        foreach ($add as $column => $definition) {
            if (!isset($columns[strtolower($column)])) {
                $pdo->exec("ALTER TABLE merchandise_transactions ADD COLUMN `$column` $definition");
            }
        }
    } catch (Exception $e) {
        error_log('Merchandise schema migration warning: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS merchandise_transaction_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                product_id INT NULL,
                sku VARCHAR(100) NULL,
                product_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT '',
                size_variant VARCHAR(100) NULL,
                quantity DECIMAL(10,2) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(10,2) NULL,
                line_total DECIMAL(10,2) NULL,
                item_type VARCHAR(20) NOT NULL DEFAULT 'merchandise',
                INDEX idx_transaction (transaction_id),
                INDEX idx_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $itemColumns = table_columns($pdo, 'merchandise_transaction_items');
        $itemAdd = [
            'sku' => 'VARCHAR(100) NULL',
            'category' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'size_variant' => 'VARCHAR(100) NULL',
            'subtotal' => 'DECIMAL(10,2) NULL',
            'line_total' => 'DECIMAL(10,2) NULL',
            'item_type' => "VARCHAR(20) NOT NULL DEFAULT 'merchandise'",
        ];

        foreach ($itemAdd as $column => $definition) {
            if (!isset($itemColumns[strtolower($column)])) {
                $pdo->exec("ALTER TABLE merchandise_transaction_items ADD COLUMN `$column` $definition");
            }
        }
    } catch (Exception $e) {
        error_log('Merchandise item schema migration warning: ' . $e->getMessage());
    }
}

function resolve_current_shift(PDO $pdo, int $user_id, ?int $posted_shift_id): array
{
    $shift_id = $posted_shift_id ?: null;
    $shift_period = null;
    $shift_name = null;

    try {
        $stmt = $pdo->prepare("
            SELECT id, shift_period, shift_name
            FROM labor_sessions
            WHERE user_id = ? AND end_time IS NULL
            ORDER BY start_time DESC
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $active = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($active) {
            $shift_id = $shift_id ?: (int)$active['id'];
            $shift_period = $active['shift_period'] ?: null;
            $shift_name = $active['shift_name'] ?: null;
        } else {
            // Auto clock-in based on user profile's assigned_shift or time-based fallback
            $user_stmt = $pdo->prepare("SELECT station_id, assigned_shift FROM users WHERE id = ? LIMIT 1");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $station_id = $user['station_id'] ?? 1;
            
            $user_assigned_shift = strtolower(trim((string)($user['assigned_shift'] ?? '')));
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
            )->execute([$user_id, $station_id, $shift['shift_key'], $shift['shift_name']]);
            
            $shift_id = (int)$pdo->lastInsertId();
            $shift_period = $shift['shift_key'];
            $shift_name = $shift['shift_name'];
        }
    } catch (Exception $e) {
        error_log('Shift lookup/auto-clockin warning: ' . $e->getMessage());
    }

    if (!$shift_period) {
        $shift_period = 'first';
        $shift_name = 'First Shift: 6:00 AM – 2:00 PM';
    }

    return [$shift_id, $shift_period, $shift_name];
}

function add_dynamic_column(array &$cols, array &$vals, array $existing, string $column, mixed $value): void
{
    if (isset($existing[strtolower($column)]) && !in_array($column, $cols, true)) {
        $cols[] = $column;
        $vals[] = $value;
    }
}

$required_fields = ['item_sku', 'quantity', 'payment_method'];
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        $_SESSION['error'] = "Missing required field: $field";
        header('Location: ../public/staff_transactions.php');
        exit;
    }
}

try {
    ensure_official_merchandise_schema($pdo);

    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $allowed_payment_methods = [
        'Cash',
        'Card',
        'Credit Card',
        'Debit Card',
        'E-Wallet',
        'E-Fuel Card',
        'Credit',
        'Credit (Utang)',
        'Account Receivable',
        'Accounts Receivable',
    ];

    if (!in_array($payment_method, $allowed_payment_methods, true)) {
        throw new Exception('Invalid payment method selected.');
    }

    $item_key = trim((string)$_POST['item_sku']);
    $product_id_key = ctype_digit($item_key) ? (int)$item_key : 0;

    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name,
            COALESCE(NULLIF(ip.sku, ''), ip.product_name) AS sku,
            COALESCE(ip.category, 'General') AS category,
            COALESCE(NULLIF(ip.size, ''), NULLIF(si.unit, ''), '') AS size_variant,
            COALESCE(si.stock_level, 0) AS stock_level,
            COALESCE(si.price, si.cost, ip.unit_cost, 0) AS unit_price
        FROM station_inventory si
        INNER JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ?
          AND COALESCE(ip.category, '') <> 'Fuel'
          AND (
                ip.id = ?
             OR LOWER(TRIM(ip.sku)) = LOWER(TRIM(?))
             OR LOWER(TRIM(ip.product_name)) = LOWER(TRIM(?))
          )
        LIMIT 1
    ");
    $stmt->execute([$station_id, $product_id_key, $item_key, $item_key]);
    $merch_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merch_data) {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name AS product_name,
                COALESCE(NULLIF(p.sku, ''), p.name) AS sku,
                COALESCE(pc.name, 'General') AS category,
                COALESCE(NULLIF(p.unit, ''), NULLIF(si.unit, ''), '') AS size_variant,
                COALESCE(si.stock_level, 0) AS stock_level,
                COALESCE(si.price, p.price, si.cost, p.cost, 0) AS unit_price
            FROM station_inventory si
            INNER JOIN products p ON p.id = si.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE si.station_id = ?
              AND p.type_id = 2
              AND (
                    p.id = ?
                 OR LOWER(TRIM(p.sku)) = LOWER(TRIM(?))
                 OR LOWER(TRIM(p.name)) = LOWER(TRIM(?))
              )
            LIMIT 1
        ");
        $stmt->execute([$station_id, $product_id_key, $item_key, $item_key]);
        $merch_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$merch_data) {
        throw new Exception('Invalid merchandise item selected.');
    }

    $product_id = (int)$merch_data['id'];
    $resolved_item_sku = $merch_data['sku'] ?: $item_key;
    $resolved_product_name = $merch_data['product_name'] ?: $item_key;
    $category = $merch_data['category'] ?? 'General';
    $size_variant = $merch_data['size_variant'] ?? '';
    $unit_price = (float)$merch_data['unit_price'];
    $quantity = (float)$_POST['quantity'];
    $subtotal_amount = round($quantity * $unit_price, 2);
    $vat_amount = 0.00;
    $total_amount = $subtotal_amount;

    if ($quantity <= 0) {
        throw new Exception('Invalid quantity: Quantity must be greater than 0.');
    }
    if ($unit_price <= 0) {
        throw new Exception('Invalid unit price for the selected item.');
    }
    if ($total_amount <= 0) {
        throw new Exception('Invalid computation: Total amount must be greater than 0.');
    }

    $customer_name = trim((string)($_POST['customer_name'] ?? ''));
    if ($customer_name === '') {
        $customer_name = 'Walk-in Customer';
    }

    $name_parts = explode(' ', $customer_name, 2);
    $customer_first_name = $name_parts[0] ?? null;
    $customer_last_name = $name_parts[1] ?? null;
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $amount_tendered = (float)($_POST['amount_tendered'] ?? 0);
    $change_amount = max(0, $amount_tendered - $total_amount);
    $is_credit = in_array(strtolower($payment_method), ['credit', 'credit (utang)', 'account receivable', 'accounts receivable'], true);
    $credit_customer_id_raw = trim((string)($_POST['credit_customer_id'] ?? ''));
    $credit_customer_id = ctype_digit($credit_customer_id_raw) ? (int)$credit_customer_id_raw : null;

    if ($is_credit && !$credit_customer_id) {
        throw new Exception('Credit customer ID is required for credit transactions.');
    }

    $payment_status = $is_credit ? 'Credit Transaction' : 'Paid';
    $amount_paid = $is_credit ? 0.00 : ($amount_tendered > 0 ? min($amount_tendered, $total_amount) : $total_amount);
    $balance_due = $is_credit ? $total_amount : max(0, $total_amount - $amount_paid);
    $posted_shift_id = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
    [$shift_id, $shift_period, $shift_name] = resolve_current_shift($pdo, (int)$me['id'], $posted_shift_id);
    $transaction_timestamp = trim((string)($_POST['transaction_timestamp'] ?? ''));
    if ($transaction_timestamp === '') {
        $transaction_timestamp = date('Y-m-d H:i:s');
    }

    $transaction_id = 'MERCH_' . date('YmdHis') . '_' . $station_id . '_' . $me['id'] . '_' . mt_rand(100, 999);

    $pdo->beginTransaction();

    $stock_stmt = $pdo->prepare("
        SELECT stock_level
        FROM station_inventory
        WHERE station_id = ? AND product_id = ?
        FOR UPDATE
    ");
    $stock_stmt->execute([$station_id, $product_id]);
    $stock_level = $stock_stmt->fetchColumn();
    if ($stock_level === false) {
        throw new Exception('Inventory record is missing for the selected item.');
    }
    if ((float)$stock_level < $quantity) {
        throw new Exception('Insufficient stock. Available: ' . number_format((float)$stock_level, 2) . ' units.');
    }

    if ($is_credit) {
        $customer_stmt = $pdo->prepare("
            SELECT credit_limit, balance, status
            FROM customers
            WHERE id = ? AND station_id = ?
            LIMIT 1
        ");
        $customer_stmt->execute([$credit_customer_id, $station_id]);
        $credit_customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$credit_customer) {
            throw new Exception('Selected credit customer was not found.');
        }
        if (in_array(strtolower((string)$credit_customer['status']), ['inactive', 'suspended', 'locked'], true)) {
            throw new Exception('Transaction blocked: customer account is not active.');
        }

        $available_credit = (float)$credit_customer['credit_limit'] - (float)$credit_customer['balance'];
        if ($available_credit < $total_amount) {
            throw new Exception('Insufficient credit limit. Available: PHP ' . number_format($available_credit, 2) . '.');
        }
    }

    $mt_columns = table_columns($pdo, 'merchandise_transactions');
    $cols = [];
    $vals = [];

    add_dynamic_column($cols, $vals, $mt_columns, 'transaction_id', $transaction_id);
    add_dynamic_column($cols, $vals, $mt_columns, 'station_id', $station_id);
    add_dynamic_column($cols, $vals, $mt_columns, 'staff_id', $me['id']);
    add_dynamic_column($cols, $vals, $mt_columns, 'transaction_date', $transaction_timestamp);
    add_dynamic_column($cols, $vals, $mt_columns, 'customer_name', $customer_name);
    add_dynamic_column($cols, $vals, $mt_columns, 'customer_first_name', $customer_first_name);
    add_dynamic_column($cols, $vals, $mt_columns, 'customer_last_name', $customer_last_name);
    add_dynamic_column($cols, $vals, $mt_columns, 'credit_customer_id', $credit_customer_id);
    add_dynamic_column($cols, $vals, $mt_columns, 'item_sku', $resolved_item_sku);
    add_dynamic_column($cols, $vals, $mt_columns, 'quantity', (int)ceil($quantity));
    add_dynamic_column($cols, $vals, $mt_columns, 'unit_price', $unit_price);
    add_dynamic_column($cols, $vals, $mt_columns, 'subtotal_amount', $subtotal_amount);
    add_dynamic_column($cols, $vals, $mt_columns, 'vat_amount', $vat_amount);
    add_dynamic_column($cols, $vals, $mt_columns, 'total_amount', $total_amount);
    add_dynamic_column($cols, $vals, $mt_columns, 'payment_method', $payment_method);
    add_dynamic_column($cols, $vals, $mt_columns, 'payment_status', $payment_status);
    add_dynamic_column($cols, $vals, $mt_columns, 'amount_paid', $amount_paid > 0 ? $amount_paid : null);
    add_dynamic_column($cols, $vals, $mt_columns, 'balance_due', $balance_due > 0 ? $balance_due : null);
    add_dynamic_column($cols, $vals, $mt_columns, 'amount_tendered', $amount_tendered > 0 ? $amount_tendered : null);
    add_dynamic_column($cols, $vals, $mt_columns, 'change_amount', $change_amount);
    add_dynamic_column($cols, $vals, $mt_columns, 'card_reference', $_POST['card_reference'] ?? null);
    add_dynamic_column($cols, $vals, $mt_columns, 'card_type', $_POST['card_type'] ?? null);
    add_dynamic_column($cols, $vals, $mt_columns, 'ewallet_reference', $_POST['ewallet_reference'] ?? null);
    add_dynamic_column($cols, $vals, $mt_columns, 'ewallet_provider', $_POST['ewallet_provider'] ?? null);
    add_dynamic_column($cols, $vals, $mt_columns, 'efuel_card_number', $_POST['efuel_card_number'] ?? null);
    add_dynamic_column($cols, $vals, $mt_columns, 'remarks', $remarks);
    add_dynamic_column($cols, $vals, $mt_columns, 'staff_remarks', $remarks);
    add_dynamic_column($cols, $vals, $mt_columns, 'validation_status', 'Official');
    add_dynamic_column($cols, $vals, $mt_columns, 'workflow_status', 'Completed');
    add_dynamic_column($cols, $vals, $mt_columns, 'inventory_deducted', 1);
    add_dynamic_column($cols, $vals, $mt_columns, 'shift_id', $shift_id);
    add_dynamic_column($cols, $vals, $mt_columns, 'shift_period', $shift_period);
    add_dynamic_column($cols, $vals, $mt_columns, 'shift_name', $shift_name);
    add_dynamic_column($cols, $vals, $mt_columns, 'transaction_type', 'merchandise');
    add_dynamic_column($cols, $vals, $mt_columns, 'created_at', $transaction_timestamp);
    add_dynamic_column($cols, $vals, $mt_columns, 'updated_at', date('Y-m-d H:i:s'));

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $column_list = implode(', ', array_map(fn($col) => "`$col`", $cols));
    $insert_stmt = $pdo->prepare("INSERT INTO merchandise_transactions ($column_list) VALUES ($placeholders)");
    $insert_stmt->execute($vals);
    $transaction_db_id = (int)$pdo->lastInsertId();

    $item_columns = table_columns($pdo, 'merchandise_transaction_items');
    $item_cols = [];
    $item_vals = [];
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'transaction_id', $transaction_db_id);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'product_id', $product_id);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'sku', $resolved_item_sku);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'product_name', $resolved_product_name);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'category', $category);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'size_variant', $size_variant);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'quantity', $quantity);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'unit_price', $unit_price);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'subtotal', $subtotal_amount);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'line_total', $subtotal_amount);
    add_dynamic_column($item_cols, $item_vals, $item_columns, 'item_type', 'merchandise');

    $item_placeholders = implode(', ', array_fill(0, count($item_cols), '?'));
    $item_column_list = implode(', ', array_map(fn($col) => "`$col`", $item_cols));
    $item_stmt = $pdo->prepare("INSERT INTO merchandise_transaction_items ($item_column_list) VALUES ($item_placeholders)");
    $item_stmt->execute($item_vals);

    // Deduct stock and record movement via Global Movement Engine
    try {
        record_merchandise_sale_movement(
            $pdo,
            $station_id,
            $product_id,
            (float)$quantity,
            $transaction_id,
            (int)($user_id ?: ($me['id'] ?? 0))
        );
    } catch (Exception $eStock) {
        error_log("Merchandise sale stock movement failed: " . $eStock->getMessage());
    }

    if ($is_credit && $credit_customer_id) {
        $pdo->prepare("
            UPDATE customers
            SET balance = COALESCE(balance, 0) + ?
            WHERE id = ? AND station_id = ?
        ")->execute([$total_amount, $credit_customer_id, $station_id]);

        try {
            $balance_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ? AND station_id = ?");
            $balance_stmt->execute([$credit_customer_id, $station_id]);
            $running_balance = (float)$balance_stmt->fetchColumn();
            $pdo->prepare("
                INSERT INTO customer_credit_transactions (
                    customer_id, transaction_id, transaction_type, amount,
                    running_balance, description, station_id, created_by, created_at
                ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $credit_customer_id,
                $transaction_id,
                $total_amount,
                $running_balance,
                'Official merchandise sale - Ref: ' . $transaction_id,
                $station_id,
                $me['id'],
            ]);
        } catch (Exception $e) {
            error_log('Credit transaction log warning: ' . $e->getMessage());
        }
    }

    try {
        $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, log_type, action_type, action_details,
                entity_type, entity_id, station_id, ip_address, user_agent, created_at
            ) VALUES (?, 'TRANSACTION', 'Create', ?, 'merchandise_transactions', ?, ?, ?, ?, NOW())
        ")->execute([
            $me['id'],
            "Official merchandise transaction saved: {$transaction_id} - {$resolved_product_name} x {$quantity} - PHP " . number_format($total_amount, 2),
            $transaction_db_id,
            $station_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    } catch (Exception $e) {
        error_log('Audit log warning: ' . $e->getMessage());
    }

    try {
        $pdo->prepare("
            INSERT INTO calendar_events (
                station_id, event_type, event_title, event_description,
                event_date, created_by, created_at
            ) VALUES (?, 'merchandise_transaction', ?, ?, DATE(?), ?, NOW())
        ")->execute([
            $station_id,
            "Transaction: {$transaction_id}",
            "Merchandise released: {$resolved_product_name} x {$quantity}",
            $transaction_timestamp,
            $me['id'],
        ]);
    } catch (Exception $e) {
        error_log('Calendar event warning: ' . $e->getMessage());
    }

    if (function_exists('log_activity')) {
        log_activity(
            $pdo,
            $me['id'],
            'Merchandise Transaction Saved',
            "Official transaction {$transaction_id} saved by staff. Inventory deducted immediately."
        );
    }

    $pdo->commit();

    $receipt_data = [
        'transaction_id' => $transaction_id,
        'status' => 'Official',
        'staff_id' => $me['id'],
        'staff_name' => $me['name'] ?? $me['username'],
        'shift_status' => $shift_name ?: $shift_period,
        'shift_id' => $shift_id,
        'transaction_timestamp' => $transaction_timestamp,
        'customer_name' => $customer_name,
        'items' => [[
            'sku' => $resolved_item_sku,
            'name' => $resolved_product_name,
            'category' => $category,
            'size_variant' => $size_variant,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $subtotal_amount,
        ]],
        'total_amount' => $total_amount,
        'payment_method' => $payment_method,
        'payment_status' => $payment_status,
        'station_id' => $station_id,
        'remarks' => $remarks,
    ];

    unset($_SESSION['pending_receipt_data'], $_SESSION['last_pending_transaction']);
    $_SESSION['receipt_data'] = $receipt_data;
    $_SESSION['receipt_generated'] = true;
    $_SESSION['last_transaction_id'] = $transaction_id;
    $_SESSION['success'] = "Transaction saved successfully. TXN: {$transaction_id} | Status: Official | Items: {$quantity} x {$resolved_product_name} | PHP " . number_format($total_amount, 2);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = 'Error processing merchandise transaction: ' . $e->getMessage();
}

header('Location: ../public/staff_transactions.php');
exit;
