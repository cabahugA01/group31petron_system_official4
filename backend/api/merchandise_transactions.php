<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Ensure session is active (lib.php may have already started it)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Auth check — session stores user under $_SESSION['user']
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetRequest($pdo, $station_id, $role, $me);
            break;
        case 'POST':
            handlePostRequest($pdo, $station_id, $role, $me);
            break;
        case 'PUT':
            handlePutRequest($pdo, $station_id, $role, $me);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Merchandise Transactions API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

function handleGetRequest($pdo, $station_id, $role, $me) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_products':
            getMerchandiseProducts($pdo, $station_id);
            break;
        case 'get_customers':
            getCreditCustomers($pdo, $station_id);
            break;
        case 'lookup_job_order':
            lookupJobOrder($pdo, $station_id);
            break;
        case 'get_pending_transactions':
            getPendingTransactions($pdo, $station_id, $role);
            break;
        case 'get_transaction_details':
            getTransactionDetails($pdo, $station_id, $role);
            break;
        case 'get_payment_methods':
            getPaymentMethods($pdo);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePostRequest($pdo, $station_id, $role, $me) {
    $action = $_POST['action'] ?? '';

    // Also support JSON body action (from fetch() calls)
    // Read the raw body ONCE and cache it so downstream functions can reuse it
    $rawBody = file_get_contents('php://input');
    $GLOBALS['_cached_request_body'] = $rawBody;

    if (empty($action)) {
        $body = json_decode($rawBody, true);
        $action = $body['action'] ?? '';
    }
    
    switch ($action) {
        case 'create_transaction':
        case 'create_merchandise_transaction':
            createMerchandiseTransaction($pdo, $station_id, $role, $me);
            break;
        case 'validate_transaction':
            validateTransaction($pdo, $station_id, $role, $me);
            break;
        case 'reject_transaction':
            rejectTransaction($pdo, $station_id, $role, $me);
            break;
        case 'adjust_transaction':
            adjustTransaction($pdo, $station_id, $role, $me);
            break;
        case 'log_failed_transaction':
            logFailedTransactionAttempt($pdo, $station_id, $me);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function getMerchandiseProducts($pdo, $station_id) {
    try {
        // Get ALL merchandise products from inventory_products, excluding fuel
        $stmt = $pdo->prepare("
            SELECT
                ip.id AS product_id,
                ip.product_name,
                COALESCE(NULLIF(ip.sku, ''), ip.product_name) AS sku,
                COALESCE(ip.category, 'General') AS category,
                COALESCE(NULLIF(ip.size, ''), '') AS size,
                COALESCE(ip.unit_cost, 0) AS unit_price,
                COALESCE(si.stock_level, 0) AS stock_level,
                CASE 
                    WHEN si.stock_level > 0 AND COALESCE(si.status, 'active') = 'active' THEN 'Available'
                    WHEN si.stock_level <= 0 THEN 'Out of Stock'
                    ELSE 'Not Available'
                END as availability_status
            FROM inventory_products ip
            LEFT JOIN station_inventory si 
                ON ip.id = si.product_id 
                AND si.station_id = ?
            WHERE COALESCE(ip.category, '') <> 'Fuel'
            ORDER BY COALESCE(ip.category, 'General'), ip.product_name
        ");
        $stmt->execute([$station_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'products' => $products
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching products: ' . $e->getMessage());
    }
}

function getCreditCustomers($pdo, $station_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, credit_limit, balance, 
                   (credit_limit - balance) AS available_credit
            FROM customers 
            WHERE station_id = ? AND status = 'active' 
            ORDER BY name
        ");
        $stmt->execute([$station_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'customers' => $customers
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching customers: ' . $e->getMessage());
    }
}

function lookupJobOrder($pdo, $station_id) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'results' => []]);
        return;
    }
    try {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(jo.job_order_number, jo.job_order_id, CONCAT('JO-', jo.id)) AS jo_ref,
                COALESCE(jo.customer_name, c.name, 'Walk-in Customer')               AS customer_name,
                COALESCE(jo.service_type, jo.service_description, '')                AS service_type,
                jo.id
            FROM job_orders jo
            LEFT JOIN customers c ON c.id = jo.customer_id
            WHERE jo.station_id = ?
              AND (
                  jo.job_order_number LIKE ?
                  OR jo.job_order_id  LIKE ?
                  OR c.name           LIKE ?
                  OR jo.customer_name LIKE ?
              )
            ORDER BY jo.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$station_id, $like, $like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'results' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'results' => [], 'error' => $e->getMessage()]);
    }
}

function getPendingTransactions($pdo, $station_id, $role) {
    // Only managers and above can view pending transactions
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                mt.*,
                u.name AS staff_name,
                c.name AS customer_name,
                mt.validation_status
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            LEFT JOIN customers c ON mt.credit_customer_id = c.id
            WHERE mt.station_id = ? AND mt.validation_status = 'Pending'
            ORDER BY mt.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get transaction items and derive transaction_type for each
        foreach ($transactions as &$transaction) {
            $iStmt = $pdo->prepare("
                SELECT 
                    mti.*,
                    ip.product_name,
                    ip.category,
                    ip.size
                FROM merchandise_transaction_items mti
                LEFT JOIN inventory_products ip ON mti.product_id = ip.id
                WHERE mti.transaction_id = ?
            ");
            $iStmt->execute([$transaction['id']]);
            $transaction['items'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);

            // Derive transaction_type if not stored (backward compat)
            if (empty($transaction['transaction_type'])) {
                $has_svc = false; $has_mrc = false;
                foreach ($transaction['items'] as $it) {
                    if (($it['item_type'] ?? 'merchandise') === 'service') $has_svc = true;
                    else $has_mrc = true;
                }
                if (!empty($transaction['job_order_service'])) $has_svc = true;
                $transaction['transaction_type'] = ($has_svc && $has_mrc) ? 'combined'
                    : ($has_svc ? 'job_order' : 'merchandise');
            }
        }
        unset($transaction); // break reference
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching pending transactions: ' . $e->getMessage());
    }
}

function getTransactionDetails($pdo, $station_id, $role) {
    $transaction_id = $_GET['transaction_id'] ?? '';
    
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                mt.*,
                u.name AS staff_name,
                c.name AS customer_name
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            LEFT JOIN customers c ON mt.credit_customer_id = c.id
            WHERE mt.id = ? AND mt.station_id = ?
        ");
        $stmt->execute([$transaction_id, $station_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            return;
        }
        
        // Get transaction items
        $stmt = $pdo->prepare("
            SELECT 
                mti.*,
                ip.product_name,
                ip.category,
                ip.size
            FROM merchandise_transaction_items mti
            LEFT JOIN inventory_products ip ON mti.product_id = ip.id
            WHERE mti.transaction_id = ?
        ");
        $stmt->execute([$transaction_id]);
        $transaction['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'transaction' => $transaction
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching transaction details: ' . $e->getMessage());
    }
}

function getPaymentMethods($pdo) {
    try {
        // Database-driven payment methods
        $stmt = $pdo->prepare("
            SELECT method_key, method_name, icon_class, color_class
            FROM payment_method_config
            WHERE active = 1
            ORDER BY sort_order
        ");
        $stmt->execute();
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fallback to hardcoded methods if table doesn't exist
        if (empty($payment_methods)) {
            $payment_methods = [
                ['method_key' => 'cash', 'method_name' => 'Cash', 'icon_class' => 'fas fa-money-bill-wave', 'color_class' => 'text-success'],
                ['method_key' => 'card', 'method_name' => 'Credit Card', 'icon_class' => 'fas fa-credit-card', 'color_class' => 'text-primary'],
                ['method_key' => 'credit', 'method_name' => 'Account Receivable', 'icon_class' => 'fas fa-hand-holding-usd', 'color_class' => 'text-warning']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'payment_methods' => $payment_methods
        ]);
    } catch (Exception $e) {
        // Fallback to hardcoded methods
        $payment_methods = [
            ['method_key' => 'cash', 'method_name' => 'Cash', 'icon_class' => 'fas fa-money-bill-wave', 'color_class' => 'text-success'],
            ['method_key' => 'card', 'method_name' => 'Credit Card', 'icon_class' => 'fas fa-credit-card', 'color_class' => 'text-primary'],
            ['method_key' => 'credit', 'method_name' => 'Account Receivable', 'icon_class' => 'fas fa-hand-holding-usd', 'color_class' => 'text-warning']
        ];
        
        echo json_encode([
            'success' => true,
            'payment_methods' => $payment_methods
        ]);
    }
}

function createMerchandiseTransaction($pdo, $station_id, $role, $me) {
    // Only staff can create transactions
    if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    // Use cached body (already read in handlePostRequest) to avoid consuming the stream twice
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    // customer_name is no longer required — auto-default to Walk-in Customer
    $required_fields = ['items', 'payment_method'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    // Auto-default customer to Walk-in Customer
    if (empty($data['customer_name'])) {
        $data['customer_name'] = 'Walk-in Customer';
    }
    // Derive first/last name from payload or split from customer_name
    $data['customer_first_name'] = $data['customer_first_name'] ?? null;
    $data['customer_last_name']  = $data['customer_last_name']  ?? null;
    if (empty($data['customer_first_name']) && $data['customer_name'] !== 'Walk-in Customer') {
        $parts = explode(' ', trim($data['customer_name']), 2);
        $data['customer_first_name'] = $parts[0] ?? null;
        $data['customer_last_name']  = $parts[1] ?? null;
    }

    if (empty($data['items']) || !is_array($data['items'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Items array is required']);
        return;
    }

    // ── Safe schema migration: run DDL BEFORE opening a transaction ──────────
    // ALTER TABLE causes an implicit commit in MySQL, which would break any
    // open transaction. Run these outside the transaction block.
    // We check each column individually and add only what's missing.
    $columns_to_add = [
        'shift_id'              => 'INT NULL',
        'shift_period'          => 'VARCHAR(50) NULL',
        'shift_name'            => 'VARCHAR(100) NULL',
        'customer_name'         => "VARCHAR(255) NOT NULL DEFAULT 'Walk-in Customer'",
        'customer_first_name'   => "VARCHAR(100) NULL",
        'customer_last_name'    => "VARCHAR(100) NULL",
        'credit_customer_id'    => 'INT NULL',
        'payment_method'        => "VARCHAR(50) NOT NULL DEFAULT 'Cash'",
        'subtotal_amount'       => 'DECIMAL(10,2) NULL',
        'vat_amount'            => 'DECIMAL(10,2) NULL',
        'amount_tendered'       => 'DECIMAL(10,2) NULL',
        'change_amount'         => 'DECIMAL(10,2) NULL',
        'card_reference'        => 'VARCHAR(100) NULL',
        'card_type'             => 'VARCHAR(50) NULL',
        'ewallet_reference'     => 'VARCHAR(100) NULL',
        'ewallet_provider'      => 'VARCHAR(50) NULL',
        'efuel_card_number'     => 'VARCHAR(50) NULL',
        'remarks'               => 'TEXT NULL',
        'validation_status'     => "VARCHAR(20) NOT NULL DEFAULT 'Pending'",
        'validated_by'          => 'INT NULL',
        'validated_at'          => 'DATETIME NULL',
        'rejection_reason'      => 'TEXT NULL',
        'adjustment_reason'     => 'TEXT NULL',
        'updated_at'            => 'DATETIME NULL',
        // ── Payment and workflow tracking ─────────────────────────────────────
        'payment_status'        => "VARCHAR(30) NOT NULL DEFAULT 'Pending Payment'",
        'workflow_status'       => "VARCHAR(20) NOT NULL DEFAULT 'Pending'",
        'amount_paid'           => 'DECIMAL(10,2) NULL',
        'balance_due'           => 'DECIMAL(10,2) NULL',
        // ── Job Order integration ──────────────────────────────────────────
        'job_order_id'               => 'VARCHAR(50) NULL',
        'job_order_db_id'            => 'INT NULL',
        'job_order_service'          => 'VARCHAR(500) NULL',
        'job_order_description'      => 'TEXT NULL',
        'job_order_vehicle_plate'    => 'VARCHAR(20) NULL',
        'job_order_vehicle_type'     => 'VARCHAR(50) NULL',
        'job_order_mechanic_id'      => 'INT NULL',
        'job_order_mechanic_name'    => 'VARCHAR(255) NULL',
        'job_order_contact'          => 'VARCHAR(50) NULL',
        // ── Transaction type classification ───────────────────────────────────
        // 'job_order' = JO only (no merchandise items)
        // 'merchandise' = merchandise only (no service items)
        // 'combined' = JO + merchandise together
        'transaction_type'           => "VARCHAR(20) NOT NULL DEFAULT 'merchandise'",
    ];

    try {
        // Get existing columns
        $existing = [];
        $colRows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colRows as $col) {
            $existing[strtolower($col['Field'])] = true;
        }

        foreach ($columns_to_add as $col => $definition) {
            if (!isset($existing[strtolower($col)])) {
                $pdo->exec("ALTER TABLE merchandise_transactions ADD COLUMN `$col` $definition");
            }
        }
    } catch (Exception $e) {
        error_log("Merchandise migration warning: " . $e->getMessage());
        // Non-fatal — continue and let the INSERT fail with a clear error if needed
    }

    // ── Detect current shift (also outside transaction) ───────────────────────
    $shift_id   = $data['shift_id']     ?? null;
    $shift_key  = $data['shift_period'] ?? null;
    $shift_name = $data['shift_name']   ?? null;

    try {
        $stmt = $pdo->prepare("SELECT id, shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $stmt->execute([$me['id']]);
        $active_session = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($active_session) {
            // Only use labor_session id as shift_id if it actually exists in the shifts table
            $shift_key  = $shift_key  ?: ($active_session['shift_period'] ?? null);
            $shift_name = $shift_name ?: ($active_session['shift_name']   ?? null);
        }
    } catch (Exception $e) {}

    // Resolve shift_id from the shifts table (not labor_sessions)
    // Try to find an active shift for this station/staff
    if (!$shift_id) {
        try {
            $scheck = $pdo->prepare("SELECT id FROM shifts WHERE station_id = ? AND staff_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
            $scheck->execute([$station_id, $me['id']]);
            $active_shift = $scheck->fetch(PDO::FETCH_ASSOC);
            if ($active_shift) {
                $shift_id = $active_shift['id'];
            }
        } catch (Exception $e) {}
    }

    // Validate that shift_id actually exists in shifts table — set NULL if not
    if ($shift_id) {
        try {
            $sv = $pdo->prepare("SELECT id FROM shifts WHERE id = ? LIMIT 1");
            $sv->execute([$shift_id]);
            if (!$sv->fetch()) {
                $shift_id = null; // FK would fail — use NULL instead
            }
        } catch (Exception $e) {
            $shift_id = null;
        }
    }

    if (!$shift_key) {
        try {
            $ct = date('H:i:s');
            $sp = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 AND start_time <= ? AND end_time >= ? ORDER BY sort_order ASC LIMIT 1");
            $sp->execute([$ct, $ct]);
            $sf = $sp->fetch(PDO::FETCH_ASSOC);
            if ($sf) {
                $shift_key  = $sf['shift_key'];
                $shift_name = $sf['shift_name'];
            } else {
                $sp2 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1");
                $sf2 = $sp2->fetch(PDO::FETCH_ASSOC);
                if ($sf2) { $shift_key = $sf2['shift_key']; $shift_name = $sf2['shift_name']; }
            }
        } catch (Exception $e) {}
    }

    // ── Calculate totals ──────────────────────────────────────────────────────
    // Use frontend-computed values (subtotal + VAT = grand total).
    // Fall back to summing items if not provided.
    $items_subtotal = 0;
    foreach ($data['items'] as $item) {
        $items_subtotal += floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0);
    }
    $subtotal_amount = floatval($data['subtotal']     ?? $items_subtotal);
    $vat_amount      = floatval($data['vat_amount']   ?? ($subtotal_amount * 0.12));
    $total_amount    = floatval($data['total_amount'] ?? ($subtotal_amount + $vat_amount));

    // Sanity-check: if frontend grand total doesn't match items sum + VAT, recompute
    $expected_grand = round($items_subtotal * 1.12, 2);
    if (abs($total_amount - $expected_grand) > 0.10) {
        // Frontend total deviates too much — recompute from items
        $subtotal_amount = $items_subtotal;
        $vat_amount      = round($items_subtotal * 0.12, 2);
        $total_amount    = round($items_subtotal + $vat_amount, 2);
    }

    // ── Payment method + amount setup ─────────────────────────────────────────
    $payment_method  = $data['payment_method'];
    // amount_paid: the actual amount the customer paid/tendered right now
    $amount_paid = floatval($data['amount_paid'] ?? $data['amount_tendered'] ?? 0);

    // ── Determine payment_status based on amount vs total ─────────────────────
    // Credit (Utang) is a special case — always Credit Transaction
    // For Cash/Card/E-Wallet/E-Fuel Card:
    //   amount_paid = 0          → Pending Payment
    //   0 < amount_paid < total  → Partial Payment
    //   amount_paid >= total     → Paid
    if ($payment_method === 'Credit') {
        $resolved_payment_status = 'Credit Transaction';
        // Credit requires a customer account
        $credit_customer_id = intval($data['credit_customer_id'] ?? 0);
        if (!$credit_customer_id) {
            http_response_code(400);
            echo json_encode(['error' => 'A credit account must be selected for Credit (Utang) transactions.']);
            return;
        }
        // Check credit limit and customer status
        try {
            $cstmt = $pdo->prepare("SELECT credit_limit, balance, status FROM customers WHERE id = ? AND station_id = ? LIMIT 1");
            $cstmt->execute([$credit_customer_id, $station_id]);
            $cust = $cstmt->fetch(PDO::FETCH_ASSOC);
            if ($cust) {
                if (($cust['status'] ?? '') === 'locked') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Transaction blocked: Customer account is locked.']);
                    return;
                }
                if (($cust['status'] ?? '') === 'inactive') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Transaction blocked: Customer account is inactive.']);
                    return;
                }
                $available = floatval($cust['credit_limit']) - floatval($cust['balance']);
                if ($total_amount > $available) {
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Insufficient credit limit. Grand total is ₱' . number_format($total_amount, 2)
                                 . ' but only ₱' . number_format($available, 2) . ' is available.'
                    ]);
                    return;
                }
            }
        } catch (Exception $e) {
            error_log("Credit limit check warning: " . $e->getMessage());
        }
        $balance_due = $total_amount; // full amount is on credit
    } else {
        // Cash / Card / E-Wallet / E-Fuel Card
        if ($amount_paid <= 0) {
            $resolved_payment_status = 'Pending Payment';
            $balance_due = $total_amount;
        } elseif ($amount_paid < $total_amount - 0.009) {
            // Partial — round tolerance of 1 cent
            $resolved_payment_status = 'Partial Payment';
            $balance_due = round($total_amount - $amount_paid, 2);
        } else {
            $resolved_payment_status = 'Paid';
            $balance_due = 0;
        }
    }

    // ── Ensure items table exists (DDL outside transaction) ──────────────────
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS merchandise_transaction_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT '',
                size_variant VARCHAR(100) NULL,
                quantity DECIMAL(10,2) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL,
                INDEX idx_transaction (transaction_id),
                INDEX idx_product (product_id)
            )
        ");
    } catch (Exception $e) { /* table already exists — ignore */ }

    // ── Stock availability validation (merchandise items only) ──────────────
    try {
        foreach ($data['items'] as $item) {
            // Skip service fee items — they have no product_id
            if (($item['item_type'] ?? 'merchandise') === 'service') continue;

            $product_id = intval($item['product_id'] ?? 0);
            $qty        = floatval($item['quantity'] ?? 0);
            if (!$product_id || $qty <= 0) continue;

            $stockStmt = $pdo->prepare("
                SELECT COALESCE(stock_level, 0) AS stock_level, ip.product_name
                FROM station_inventory si
                JOIN inventory_products ip ON ip.id = si.product_id
                WHERE si.station_id = ? AND si.product_id = ?
                LIMIT 1
            ");
            $stockStmt->execute([$station_id, $product_id]);
            $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$stockRow) {
                http_response_code(400);
                echo json_encode(['error' => "Product not found in station inventory: " . htmlspecialchars($item['product_name'] ?? "ID $product_id")]);
                return;
            }
            if ($stockRow['stock_level'] < $qty) {
                http_response_code(400);
                echo json_encode([
                    'error' => "Insufficient stock for \"{$stockRow['product_name']}\". "
                             . "Available: " . number_format($stockRow['stock_level'], 2)
                             . ", Requested: " . number_format($qty, 2)
                ]);
                return;
            }
        }
    } catch (Exception $e) {
        error_log("Stock validation warning: " . $e->getMessage());
        // Non-fatal if station_inventory table is missing — proceed
    }

    // Generate unique transaction ID
    $transaction_id = 'MERCH' . date('Y') . str_pad($station_id, 3, '0', STR_PAD_LEFT) . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

    try {
        $pdo->beginTransaction();

        // Build INSERT dynamically based on what columns actually exist
        // (handles both old minimal schema and new full schema)
        $existingCols = [];
        $colRows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colRows as $col) {
            $existingCols[strtolower($col['Field'])] = true;
        }

        $has = function($col) use ($existingCols) {
            return isset($existingCols[strtolower($col)]);
        };

        // Always-present columns (original schema)
        $cols   = ['transaction_id', 'station_id', 'staff_id', 'total_amount'];
        $vals   = [$transaction_id, $station_id, $me['id'], $total_amount];

        // Optional columns — add only if they exist
        $optional = [
            'shift_id'              => $shift_id ?: null,
            'shift_period'          => $shift_key,
            'shift_name'            => $shift_name,
            'customer_name'         => $data['customer_name'],
            'customer_first_name'   => $data['customer_first_name'] ?? null,
            'customer_last_name'    => $data['customer_last_name']  ?? null,
            'credit_customer_id'    => $data['credit_customer_id'] ?? null,
            'payment_method'        => $data['payment_method'],
            'subtotal_amount'       => $subtotal_amount,
            'vat_amount'            => $vat_amount,
            'remarks'               => $data['remarks'] ?? '',
            'validation_status'     => 'Pending',
            'amount_tendered'       => $data['amount_tendered'] ?? null,
            'change_amount'         => $data['change_amount'] ?? null,
            'card_reference'        => $data['card_reference'] ?? null,
            'card_type'             => $data['card_type'] ?? null,
            'ewallet_reference'     => $data['ewallet_reference'] ?? null,
            'ewallet_provider'      => $data['ewallet_provider'] ?? null,
            'efuel_card_number'          => $data['efuel_card_number'] ?? null,
            // ── Payment tracking fields ──────────────────────────────────────────
            'amount_paid'           => $amount_paid > 0 ? $amount_paid : null,
            'balance_due'           => $balance_due > 0 ? $balance_due : null,
            // payment_status: Paid / Partial Payment / Pending Payment / Credit Transaction
            'payment_status'        => $resolved_payment_status,
            // ── Workflow status: tracks In Progress / Completed after manager approval ──
            'workflow_status'       => 'Pending',
            // ── Job Order integration ──────────────────────────────────────
            'job_order_id'               => !empty($data['job_order_id'])            ? $data['job_order_id']               : null,
            'job_order_db_id'            => !empty($data['job_order_db_id'])         ? (int)$data['job_order_db_id']       : null,
            'job_order_service'          => !empty($data['job_order_service'])       ? $data['job_order_service']          : null,
            'job_order_description'      => !empty($data['job_order_description'])   ? $data['job_order_description']      : null,
            'job_order_vehicle_plate'    => !empty($data['job_order_vehicle_plate']) ? $data['job_order_vehicle_plate']    : null,
            'job_order_vehicle_type'     => !empty($data['job_order_vehicle_type'])  ? $data['job_order_vehicle_type']     : null,
            'job_order_mechanic_id'      => !empty($data['job_order_mechanic_id'])   ? (int)$data['job_order_mechanic_id'] : null,
            'job_order_mechanic_name'    => !empty($data['job_order_mechanic_name']) ? $data['job_order_mechanic_name']    : null,
            'job_order_contact'          => !empty($data['job_order_contact'])       ? $data['job_order_contact']          : null,
            // ── Transaction type: classify based on cart contents ──────────────
            // Determined by whether items contain service-type and/or merchandise-type entries
            'transaction_type'           => (function() use ($data) {
                $has_service = false;
                $has_merch   = false;
                foreach ($data['items'] as $item) {
                    if (($item['item_type'] ?? 'merchandise') === 'service') {
                        $has_service = true;
                    } else {
                        $has_merch = true;
                    }
                }
                if ($has_service && $has_merch) return 'combined';
                if ($has_service)               return 'job_order';
                return 'merchandise';
            })(),
        ];

        foreach ($optional as $col => $val) {
            if ($has($col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        }

        // Handle transaction_date (old schema) vs created_at (new schema)
        if ($has('transaction_date') && !$has('created_at')) {
            $cols[] = 'transaction_date';
            $vals[] = date('Y-m-d H:i:s');
        }

        // Handle updated_at
        if ($has('updated_at')) {
            $cols[] = 'updated_at';
            $vals[] = date('Y-m-d H:i:s');
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList      = implode(', ', array_map(fn($c) => "`$c`", $cols));

        $stmt = $pdo->prepare("INSERT INTO merchandise_transactions ($colList) VALUES ($placeholders)");
        $stmt->execute($vals);

        $merch_transaction_id = $pdo->lastInsertId();

        // Insert transaction items
        // ── Ensure items table has item_type column ───────────────────────────
        try {
            $itemColCheck = $pdo->query("SHOW COLUMNS FROM merchandise_transaction_items LIKE 'item_type'")->rowCount();
            if ($itemColCheck === 0) {
                $pdo->exec("ALTER TABLE merchandise_transaction_items ADD COLUMN `item_type` VARCHAR(20) NOT NULL DEFAULT 'merchandise' AFTER subtotal");
            }
            // Also ensure product_id allows NULL (for service items)
            $pidColRow = $pdo->query("SHOW COLUMNS FROM merchandise_transaction_items LIKE 'product_id'")->fetch(PDO::FETCH_ASSOC);
            if ($pidColRow && strpos(strtolower($pidColRow['Type']), 'int') !== false && strtolower($pidColRow['Null']) === 'no') {
                $pdo->exec("ALTER TABLE merchandise_transaction_items MODIFY COLUMN `product_id` INT NULL");
            }
        } catch (Exception $e) {
            error_log("Items table migration warning: " . $e->getMessage());
        }

        $itemColRows = $pdo->query("SHOW COLUMNS FROM merchandise_transaction_items")->fetchAll(PDO::FETCH_ASSOC);
        $itemCols = [];
        foreach ($itemColRows as $col) {
            $itemCols[strtolower($col['Field'])] = true;
        }

        $hasItem = function($col) use ($itemCols) {
            return isset($itemCols[strtolower($col)]);
        };

        foreach ($data['items'] as $item) {
            $isService = ($item['item_type'] ?? 'merchandise') === 'service';
            $subtotal  = floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0);

            $iCols = ['transaction_id', 'product_name', 'quantity', 'unit_price', 'subtotal'];
            $iVals = [
                $merch_transaction_id,
                $item['product_name'] ?? ($isService ? 'Service Fee' : 'Item'),
                $item['quantity'] ?? 1,
                $item['unit_price'] ?? 0,
                $subtotal,
            ];

            // product_id — NULL for service items
            if ($hasItem('product_id')) {
                $iCols[] = 'product_id';
                $iVals[] = $isService ? null : (intval($item['product_id'] ?? 0) ?: null);
            }

            if ($hasItem('category')) {
                $iCols[] = 'category';
                $iVals[] = $item['category'] ?? ($isService ? 'Service Fee' : '');
            }
            if ($hasItem('size_variant')) {
                $iCols[] = 'size_variant';
                $iVals[] = $item['size_variant'] ?? '';
            }
            if ($hasItem('item_type')) {
                $iCols[] = 'item_type';
                $iVals[] = $isService ? 'service' : 'merchandise';
            }

            $iPlaceholders = implode(', ', array_fill(0, count($iCols), '?'));
            $iColList      = implode(', ', array_map(fn($c) => "`$c`", $iCols));

            $itemStmt = $pdo->prepare("INSERT INTO merchandise_transaction_items ($iColList) VALUES ($iPlaceholders)");
            $itemStmt->execute($iVals);
        }

        $pdo->commit();

        // ── Post-commit: stock deduction is intentionally deferred to manager Approve.
        // No deduction here — inventory is only committed after validation.
        // This ensures Reject = no deduction, Adjust = recalculated deduction.

        // ── Post-commit: audit logging (outside transaction so DDL won't corrupt it) ──
        try {
            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], 'Merchandise Transaction Created',
                    "Transaction ID: $transaction_id, Amount: $total_amount, Items: " . count($data['items']));
            }
            logMerchandiseTransactionAudit($pdo, $me['id'], $merch_transaction_id, 'CREATED', [
                'transaction_id' => $transaction_id,
                'total_amount'   => $total_amount,
                'item_count'     => count($data['items']),
                'customer_name'  => $data['customer_name'] ?? 'Walk-in',
                'payment_method' => $data['payment_method'] ?? '',
            ]);
        } catch (Exception $e) { /* audit failure must not block the response */ }

        echo json_encode([
            'success'              => true,
            'transaction_id'       => $transaction_id,
            'merch_transaction_id' => $merch_transaction_id,
            'message'              => 'Transaction created successfully and pending validation',
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new Exception('Error creating transaction: ' . $e->getMessage());
    }
}

function validateTransaction($pdo, $station_id, $role, $me) {
    // Only managers and above can validate
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $transaction_id = $data['transaction_id'] ?? '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID is required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE merchandise_transactions 
            SET validation_status = 'Approved',
                validated_by = ?,
                validated_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND station_id = ? AND validation_status = 'Pending'
        ");
        $stmt->execute([$me['id'], $transaction_id, $station_id]);
        
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }
        
        // Get transaction details for audit
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ?");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ── Determine transaction_type for receipt labeling ──────────────────
        $has_service = false;
        $has_merch   = false;
        $itemsForDeduction = [];
        $itemRows = $pdo->prepare("SELECT product_id, quantity, item_type FROM merchandise_transaction_items WHERE transaction_id = ?");
        $itemRows->execute([$transaction_id]);
        foreach ($itemRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['item_type'] ?? 'merchandise') === 'service') {
                $has_service = true;
            } else {
                $has_merch = true;
                if ($row['product_id'] && $row['quantity'] > 0) {
                    $itemsForDeduction[] = $row;
                }
            }
        }

        // ── Deduct stock for merchandise items on Approve ─────────────────────
        // Stock is ONLY deducted here (on manager Approve), never at creation time.
        // Scenario 1 (JO only): no merchandise items → no deduction.
        // Scenario 2 (Merch only): all items deducted here.
        // Scenario 3 (JO + Merch): only merchandise items deducted; service items skipped.
        foreach ($itemsForDeduction as $row) {
            try {
                $pdo->prepare("
                    UPDATE station_inventory
                    SET stock_level = GREATEST(stock_level - ?, 0),
                        last_updated = NOW()
                    WHERE station_id = ? AND product_id = ?
                ")->execute([$row['quantity'], $station_id, $row['product_id']]);
            } catch (Exception $e) {
                error_log("Stock deduction on approve warning: " . $e->getMessage());
            }
        }
        
        // Update customer balance if credit transaction
        if ($transaction['credit_customer_id']) {
            $cust_chk = $pdo->prepare("SELECT status FROM customers WHERE id = ?");
            $cust_chk->execute([$transaction['credit_customer_id']]);
            $cust_status = $cust_chk->fetchColumn();
            if ($cust_status === 'locked') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Approval blocked: Customer account is locked.']);
                return;
            }
            if ($cust_status === 'inactive') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Approval blocked: Customer account is inactive.']);
                return;
            }
            
            $stmt = $pdo->prepare("
                UPDATE customers 
                SET balance = balance + ? 
                WHERE id = ?
            ");
            $stmt->execute([$transaction['total_amount'], $transaction['credit_customer_id']]);
            
            // Also write to customer_credit_transactions
            try {
                // Fetch updated balance
                $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
                $bal_stmt->execute([$transaction['credit_customer_id']]);
                $new_bal = (float)$bal_stmt->fetchColumn();
                
                $cct_stmt = $pdo->prepare("
                    INSERT INTO customer_credit_transactions (
                        customer_id, transaction_id, transaction_type, amount, 
                        running_balance, description, station_id, created_by, created_at
                    ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                ");
                $cct_stmt->execute([
                    $transaction['credit_customer_id'],
                    $transaction['transaction_id'],
                    $transaction['total_amount'],
                    $new_bal,
                    "Merchandise Sale (Credit) - Ref: " . $transaction['transaction_id'],
                    $station_id,
                    $me['id']
                ]);
            } catch (Exception $ccError) {
                error_log("Error inserting into customer_credit_transactions: " . $ccError->getMessage());
            }
        }
        
        // Log to audit trail
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Merchandise Transaction Validated', 
                "Transaction ID: {$transaction['transaction_id']}, Amount: {$transaction['total_amount']}");
        }
        
        // Log detailed transaction audit
        logMerchandiseTransactionAudit($pdo, $me['id'], $transaction_id, 'APPROVED', [
            'transaction_id' => $transaction['transaction_id'],
            'total_amount' => $transaction['total_amount'],
            'customer_name' => $transaction['customer_name'],
            'payment_method' => $transaction['payment_method']
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction validated and committed successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('Error validating transaction: ' . $e->getMessage());
    }
}

function rejectTransaction($pdo, $station_id, $role, $me) {
    // Only managers and above can reject
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $transaction_id = $data['transaction_id'] ?? '';
    $reason = $data['reason'] ?? '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID is required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE merchandise_transactions 
            SET validation_status = 'Rejected',
                validated_by = ?,
                validated_at = NOW(),
                rejection_reason = ?,
                updated_at = NOW()
            WHERE id = ? AND station_id = ? AND validation_status = 'Pending'
        ");
        $stmt->execute([$me['id'], $reason, $transaction_id, $station_id]);
        
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }
        
        // Get transaction details for audit
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ?");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── No stock deduction on Reject — inventory is untouched ────────────
        // Stock is only deducted on Approve, so rejection leaves inventory intact.
        // This is logged in the audit trail for transparency.
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Merchandise Transaction Rejected', 
                "Transaction ID: {$transaction['transaction_id']}, Reason: $reason");
        }
        
        // Log detailed transaction audit
        logMerchandiseTransactionAudit($pdo, $me['id'], $transaction_id, 'REJECTED', [
            'transaction_id' => $transaction['transaction_id'],
            'total_amount' => $transaction['total_amount'],
            'customer_name' => $transaction['customer_name'],
            'rejection_reason' => $reason
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction rejected successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('Error rejecting transaction: ' . $e->getMessage());
    }
}

function adjustTransaction($pdo, $station_id, $role, $me) {
    // Only managers and above can adjust
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $transaction_id = $data['transaction_id'] ?? '';
    $adjustments = $data['adjustments'] ?? [];
    $reason = $data['reason'] ?? '';
    
    if (empty($transaction_id) || empty($adjustments)) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID and adjustments are required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get original transaction
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ?");
        $stmt->execute([$transaction_id, $station_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            return;
        }
        
        // Calculate new total
        $new_total = 0;
        foreach ($adjustments as $adjustment) {
            $new_total += $adjustment['quantity'] * $adjustment['unit_price'];
        }
        
        // Update transaction
        $stmt = $pdo->prepare("
            UPDATE merchandise_transactions 
            SET total_amount = ?,
                validation_status = 'Adjusted',
                validated_by = ?,
                validated_at = NOW(),
                adjustment_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_total, $me['id'], $reason, $transaction_id]);
        
        // Update items
        foreach ($adjustments as $adjustment) {
            $stmt = $pdo->prepare("
                UPDATE merchandise_transaction_items 
                SET quantity = ?, unit_price = ?, subtotal = ?
                WHERE transaction_id = ? AND product_id = ?
            ");
            $subtotal = $adjustment['quantity'] * $adjustment['unit_price'];
            $stmt->execute([$adjustment['quantity'], $adjustment['unit_price'], $subtotal, $transaction_id, $adjustment['product_id']]);
        }
        
        // ── Recalculate stock deduction based on adjusted quantities ─────────
        // Since stock is only deducted on Approve, and Adjust is a form of approval,
        // we deduct based on the new adjusted quantities for merchandise items only.
        $pdo->commit();

        // Post-commit: apply stock deduction for adjusted merchandise items
        foreach ($adjustments as $adjustment) {
            $pid = intval($adjustment['product_id'] ?? 0);
            $qty = floatval($adjustment['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                try {
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = GREATEST(stock_level - ?, 0),
                            last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ")->execute([$qty, $station_id, $pid]);
                } catch (Exception $e) {
                    error_log("Stock deduction on adjust warning: " . $e->getMessage());
                }
            }
        }

        // Post-commit: audit logging
        try {
            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], 'Merchandise Transaction Adjusted',
                    "Transaction ID: {$transaction['transaction_id']}, New Total: $new_total, Reason: $reason");
            }
            logMerchandiseTransactionAudit($pdo, $me['id'], $transaction_id, 'ADJUSTED', [
                'original_total'   => $transaction['total_amount'],
                'new_total'        => $new_total,
                'adjustment_reason'=> $reason,
                'adjustments'      => $adjustments,
            ]);
        } catch (Exception $e) { /* audit failure must not block response */ }
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction adjusted successfully',
            'new_total' => $new_total
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('Error adjusting transaction: ' . $e->getMessage());
    }
}

function logFailedTransactionAttempt($pdo, $station_id, $me) {
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data    = json_decode($rawBody, true) ?? [];

    $payment_method  = $data['payment_method']  ?? 'Unknown';
    $amount_tendered = floatval($data['amount_tendered'] ?? 0);
    $grand_total     = floatval($data['grand_total']     ?? 0);
    $reason          = $data['reason']          ?? 'Insufficient Payment';
    $items_count     = intval($data['items_count']       ?? 0);

    try {
        // Ensure the failed_transaction_log table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS failed_transaction_log (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                station_id    INT NOT NULL,
                staff_id      INT NOT NULL,
                payment_method VARCHAR(50) NOT NULL,
                amount_tendered DECIMAL(10,2) NOT NULL DEFAULT 0,
                grand_total   DECIMAL(10,2) NOT NULL DEFAULT 0,
                shortfall     DECIMAL(10,2) NOT NULL DEFAULT 0,
                reason        VARCHAR(255) NOT NULL,
                items_count   INT NOT NULL DEFAULT 0,
                ip_address    VARCHAR(45),
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_station  (station_id),
                INDEX idx_staff    (staff_id),
                INDEX idx_created  (created_at)
            )
        ");

        $pdo->prepare("
            INSERT INTO failed_transaction_log
                (station_id, staff_id, payment_method, amount_tendered, grand_total, shortfall, reason, items_count, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $station_id,
            $me['id'],
            $payment_method,
            $amount_tendered,
            $grand_total,
            max(0, $grand_total - $amount_tendered),
            $reason,
            $items_count,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Also write to general activity log if available
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Failed Transaction Attempt',
                "Reason: $reason | Payment: $payment_method | Tendered: $amount_tendered | Total: $grand_total | Station: $station_id");
        }

        // Notify managers via notifications table if it exists
        try {
            $mgr_stmt = $pdo->prepare("
                SELECT id FROM users
                WHERE station_id = ? AND role IN ('manager','admin','superadmin') AND status = 'active'
            ");
            $mgr_stmt->execute([$station_id]);
            $managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);

            $notif_check = $pdo->query("SHOW TABLES LIKE 'notifications'")->rowCount();
            if ($notif_check > 0 && !empty($managers)) {
                $staff_name = $me['name'] ?? $me['username'] ?? ('Staff #' . $me['id']);
                $notif_msg  = "Transaction blocked: {$staff_name} attempted a {$payment_method} payment of ₱" .
                              number_format($amount_tendered, 2) . " but Grand Total is ₱" .
                              number_format($grand_total, 2) . ". Reason: {$reason}.";
                $notif_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message, is_read, created_at)
                    VALUES (?, 'warning', ?, 0, NOW())
                ");
                foreach ($managers as $mgr) {
                    $notif_stmt->execute([$mgr['id'], $notif_msg]);
                }
            }
        } catch (Exception $e) {
            error_log("Manager notification warning: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'logged' => true]);

    } catch (Exception $e) {
        error_log("Failed transaction log error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Logging failed']);
    }
}

function logMerchandiseTransactionAudit($pdo, $user_id, $transaction_id, $action, $data = []) {
    try {
        // ── Write to merchandise_transaction_audit (existing table) ──────────
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'merchandise_transaction_audit'");
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_transaction_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                details JSON,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_transaction (transaction_id),
                INDEX idx_user (user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            )");
        }
        $details_json = json_encode($data);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $pdo->prepare("INSERT INTO merchandise_transaction_audit (transaction_id, user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$transaction_id, $user_id, $action, $details_json, $ip, $ua]);

        // ── Also write to audit_logs so the Audit Trail report shows it ──────
        $action_map = [
            'CREATED'  => 'Create',
            'APPROVED' => 'Approve',
            'REJECTED' => 'Reject',
            'ADJUSTED' => 'Adjust',
            'VOIDED'   => 'Delete',
        ];
        $action_type = $action_map[strtoupper($action)] ?? ucfirst(strtolower($action));

        // Build a human-readable detail string
        $txn_ref   = $data['transaction_id'] ?? "MT-{$transaction_id}";
        $amount    = isset($data['total_amount']) ? '₱' . number_format((float)$data['total_amount'], 2) : '';
        $items     = isset($data['item_count'])   ? $data['item_count'] . ' item(s)' : '';
        $customer  = $data['customer_name'] ?? '';
        $payment   = $data['payment_method'] ?? '';
        $parts = array_filter(["Merchandise Transaction {$action_type}", "Ref: {$txn_ref}", $amount, $items, $customer ? "Customer: {$customer}" : '', $payment ? "Payment: {$payment}" : '']);
        $detail_str = implode(' | ', $parts);

        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, new_values, status, ip_address, user_agent, created_at)
                       VALUES (?, 'transaction', ?, ?, 'merchandise_transactions', ?, ?, 'Success', ?, ?, NOW())")
            ->execute([$user_id, $action_type, $detail_str, $transaction_id, $details_json, $ip, $ua]);

    } catch (Exception $e) {
        error_log("Error logging merchandise transaction audit: " . $e->getMessage());
    }
}
?>
