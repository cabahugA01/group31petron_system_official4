<?php
/**
 * Staff Customer Operations API
 * Handles AJAX requests for customer management
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Staff, SuperAdmin, Developer only
if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Add error logging
error_log("Customer Operations - Action: $action, User: {$me['id']}, Station: $station_id");

try {
    switch ($action) {
        case 'list':
        case 'get_customers':
            listCustomers();
            break;
            
        case 'view':
        case 'get_customer':
            viewCustomer();
            break;
            
        case 'add':
        case 'add_customer':
            addCustomer();
            break;
            
        case 'update':
        case 'update_customer':
            updateCustomer();
            break;
            
        case 'check_table':
            checkTable();
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Customer Operations Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function checkTable() {
    global $pdo;
    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
        echo json_encode(['success' => true, 'table_exists' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'table_exists' => false, 'error' => $e->getMessage()]);
    }
}

function listCustomers() {
    global $pdo, $station_id;
    
    error_log("listCustomers() called - Station ID: $station_id");
    
    $search = trim($_GET['search'] ?? '');
    $type = trim($_GET['type'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to = trim($_GET['date_to'] ?? '');
    
    // Check if customers table exists
    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
        error_log("Customers table exists");
    } catch (Exception $e) {
        error_log("Customers table does NOT exist: " . $e->getMessage());
        echo json_encode([
            'success' => true,
            'customers' => [],
            'stats' => [
                'total' => 0,
                'new_today' => 0,
                'regular' => 0,
                'fleet' => 0
            ],
            'message' => 'Customers table not found. Please run setup script.'
        ]);
        return;
    }
    
    // Build query
    $where = ['station_id = ?'];
    $params = [$station_id];
    
    if ($search !== '') {
        $where[] = "(customer_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR contact_number LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    if ($type !== '') {
        $where[] = "customer_type = ?";
        $params[] = $type;
    }
    
    if ($status !== '') {
        $where[] = "status = ?";
        $params[] = $status;
    }
    
    if ($date_from !== '') {
        $where[] = "DATE(registered_at) >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to !== '') {
        $where[] = "DATE(registered_at) <= ?";
        $params[] = $date_to;
    }
    
    $whereClause = implode(' AND ', $where);
    error_log("SQL WHERE: $whereClause");
    error_log("SQL PARAMS: " . json_encode($params));
    
    // TEMPORARY FIX: Get customers without transaction counts to avoid SQL errors
    // The original query counted transactions from fuel_transactions, merchandise_transactions, and job_orders
    // but these tables may not have customer_id columns yet, causing SQL error: "Unknown column 'tt.customer_id'"
    // 
    // TO RE-ENABLE TRANSACTION COUNTS:
    // 1. Add customer_id INT(11) UNSIGNED column to fuel_transactions, merchandise_transactions, job_orders
    // 2. Add foreign key constraints to customers table
    // 3. Uncomment the original query below and remove this simplified version
    //
    // Original query (commented out):
    /*
    $stmt = $pdo->prepare("
        SELECT id, customer_id, first_name, middle_name, last_name, 
               contact_number, customer_type, status, registered_at,
               (
                   (SELECT COUNT(*) FROM fuel_transactions ft WHERE ft.customer_id = customers.id AND ft.station_id = customers.station_id) +
                   (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = customers.id AND mt.station_id = customers.station_id) +
                   (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = customers.id AND jo.station_id = customers.station_id)
               ) AS total_transactions,
               (
                   SELECT MAX(txn_date) FROM (
                       SELECT COALESCE(transaction_date, created_at) AS txn_date FROM fuel_transactions WHERE customer_id = customers.id AND station_id = customers.station_id
                       UNION ALL
                       SELECT COALESCE(transaction_date, created_at) AS txn_date FROM merchandise_transactions WHERE customer_id = customers.id AND station_id = customers.station_id
                       UNION ALL
                       SELECT created_at AS txn_date FROM job_orders WHERE customer_id = customers.id AND station_id = customers.station_id
                   ) t
               ) AS last_transaction
        FROM customers 
        WHERE $whereClause 
        ORDER BY registered_at DESC
    ");
    */
    
    // Simplified query (returns 0 transactions until integration is complete)
    $stmt = $pdo->prepare("
        SELECT id, customer_id, first_name, middle_name, last_name, 
               contact_number, customer_type, status, registered_at,
               0 AS total_transactions,
               NULL AS last_transaction
        FROM customers 
        WHERE $whereClause 
        ORDER BY registered_at DESC
    "    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Query executed successfully. Found " . count($customers) . " customers");
    
    // Get stats for summary cards
    $stats = $pdo->prepare(""
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN DATE(registered_at) = CURDATE() THEN 1 ELSE 0 END) as new_today,
            SUM(CASE WHEN customer_type = 'regular' THEN 1 ELSE 0 END) as regular,
            SUM(CASE WHEN customer_type = 'fleet' THEN 1 ELSE 0 END) as fleet
        FROM customers 
        WHERE station_id = ?
    "    ");
    $stats->execute([$station_id]);
    $statsData = $stats->fetch(PDO::FETCH_ASSOC);
    
    error_log("Stats: " . json_encode($statsData));
    error_log("Returning response with " . count($customers) . " customers");
    
    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'stats' => $statsData
    ]);
}

function viewCustomer() {
    global $pdo, $station_id;
    
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        throw new Exception('Customer ID required');
    }
    
    // Get customer details
    $stmt = $pdo->prepare("
        SELECT c.*, u.name as registered_by_name
        FROM customers c
        LEFT JOIN users u ON c.registered_by = u.id
        WHERE c.id = ? AND c.station_id = ?
    ");
    $stmt->execute([$id, $station_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    // TEMPORARY FIX: Return zero transaction counts to avoid SQL errors
    // Transaction integration disabled until customer_id columns are added to transaction tables
    // This prevents SQL error: "Unknown column 'tt.customer_id' in 'where clause'"
    //
    // TO RE-ENABLE:
    // 1. Add customer_id INT(11) UNSIGNED column to fuel_transactions, merchandise_transactions, job_orders
    // 2. Uncomment the original transaction counting queries below
    //
    // Original queries (commented out):
    /*
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amt FROM fuel_transactions WHERE customer_id = ? AND station_id = ?");
    $stmt->execute([$id, $station_id]);
    $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amt FROM merchandise_transactions WHERE customer_id = ? AND station_id = ?");
    $stmt->execute([$id, $station_id]);
    $merch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_cost), 0) as total_amt FROM job_orders WHERE customer_id = ? AND station_id = ?");
    $stmt->execute([$id, $station_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $transactions = [
        'fuel_count' => (int)($fuel['count'] ?? 0),
        'fuel_amount' => (float)($fuel['total_amt'] ?? 0),
        'merch_count' => (int)($merch['count'] ?? 0),
        'merch_amount' => (float)($merch['total_amt'] ?? 0),
        'service_count' => (int)($job['count'] ?? 0),
        'service_amount' => (float)($job['total_amt'] ?? 0),
        'total_count' => (int)(($fuel['count'] ?? 0) + ($merch['count'] ?? 0) + ($job['count'] ?? 0)),
        'total_amount' => (float)(($fuel['total_amt'] ?? 0) + ($merch['total_amt'] ?? 0) + ($job['total_amt'] ?? 0)),
        'last_transaction' => null
    ];
    
    $stmt = $pdo->prepare("
        SELECT txn_date, reference_no, module, amount FROM (
            SELECT COALESCE(transaction_date, created_at) AS txn_date, COALESCE(transaction_id, CONCAT('FT-', id)) AS reference_no, 'Fuel' AS module, total_amount AS amount FROM fuel_transactions WHERE customer_id = ? AND station_id = ?
            UNION ALL
            SELECT COALESCE(transaction_date, created_at) AS txn_date, COALESCE(transaction_number, CONCAT('MT-', id)) AS reference_no, 'Merchandise' AS module, total_amount AS amount FROM merchandise_transactions WHERE customer_id = ? AND station_id = ?
            UNION ALL
            SELECT created_at AS txn_date, COALESCE(job_order_number, CONCAT('JO-', id)) AS reference_no, 'Service' AS module, total_cost AS amount FROM job_orders WHERE customer_id = ? AND station_id = ?
        ) combined_txns
        ORDER BY txn_date DESC
        LIMIT 10
    ");
    $stmt->execute([$id, $station_id, $id, $station_id, $id, $station_id]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($recent)) {
        $transactions['last_transaction'] = $recent[0]['txn_date'];
    }
    */
    
    // Temporary values (zero counts until transaction integration)
    $transactions = [
        'fuel_count' => 0,
        'fuel_amount' => 0,
        'merch_count' => 0,
        'merch_amount' => 0,
        'service_count' => 0,
        'service_amount' => 0,
        'total_count' => 0,
        'total_amount' => 0,
        'last_transaction' => null
    ];
    
    $recent = [];
    
    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'transactions' => $transactions,
        'recent_transactions' => $recent
    ]);
}

function addCustomer() {
    global $pdo, $station_id, $me;
    
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $customerType = $_POST['customer_type'] ?? 'walk-in';
    $govIdType = $_POST['gov_id_type'] ?? null;
    
    if (!$firstName || !$lastName || !$contactNumber || !$address) {
        throw new Exception('All required fields must be filled');
    }
    
    // Generate customer ID
    $customerId = generateCustomerId($station_id);
    
    // Handle file uploads
    $govIdImage = null;
    $crDocument = null;
    
    if (!empty($_FILES['gov_id_image']['name'])) {
        $govIdImage = handleFileUpload($_FILES['gov_id_image'], 'gov_id');
    }
    
    if (!empty($_FILES['cr_document']['name'])) {
        $crDocument = handleFileUpload($_FILES['cr_document'], 'cr');
    }
    
    // Insert customer
    $stmt = $pdo->prepare("
        INSERT INTO customers (
            customer_id, station_id, first_name, middle_name, last_name,
            contact_number, address, customer_type, gov_id_type, 
            gov_id_image, cr_document, status, registered_by, registered_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
    ");
    
    $stmt->execute([
        $customerId, $station_id, $firstName, $middleName, $lastName,
        $contactNumber, $address, $customerType, $govIdType,
        $govIdImage, $crDocument, $me['id']
    ]);
    
    $newId = $pdo->lastInsertId();
    
    // Log audit
    write_audit_log($pdo, 'Create', "New customer added: $firstName $lastName ($customerId)", 'customers', $newId, 'customer');
    
    echo json_encode([
        'success' => true,
        'message' => 'Customer added successfully',
        'customer_id' => $customerId
    ]);
}

function updateCustomer() {
    global $pdo, $station_id, $me;
    
    $id = (int)($_POST['customer_id'] ?? 0);
    if (!$id) {
        throw new Exception('Customer ID required');
    }
    
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $customerType = $_POST['customer_type'] ?? 'walk-in';
    
    if (!$firstName || !$lastName || !$contactNumber || !$address) {
        throw new Exception('All required fields must be filled');
    }
    
    // Verify customer exists and belongs to station
    $check = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND station_id = ?");
    $check->execute([$id, $station_id]);
    if (!$check->fetch()) {
        throw new Exception('Customer not found or unauthorized');
    }
    
    $stmt = $pdo->prepare("
        UPDATE customers 
        SET first_name = ?, middle_name = ?, last_name = ?, 
            contact_number = ?, address = ?, customer_type = ?,
            updated_by = ?, updated_at = NOW()
        WHERE id = ? AND station_id = ?
    ");
    
    $result = $stmt->execute([
        $firstName,
        $middleName,
        $lastName,
        $contactNumber,
        $address,
        $customerType,
        $me['id'],
        $id,
        $station_id
    ]);
    
    if (!$result) {
        throw new Exception('Failed to update customer');
    }
    
    // Log audit
    write_audit_log($pdo, 'Update', "Customer updated: $firstName $lastName (ID: $id)", 'customers', $id, 'customer');
    
    echo json_encode([
        'success' => true,
        'message' => 'Customer updated successfully'
    ]);
}

function generateCustomerId($stationId) {
    global $pdo;
    
    // Format: CUS-[STATION_ID]-[YEAR][MONTH]-[SEQUENCE]
    $prefix = "CUS-{$stationId}-" . date('Ym') . "-";
    
    // Get last customer ID for this month
    $stmt = $pdo->prepare("
        SELECT customer_id FROM customers 
        WHERE customer_id LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $lastNum = (int)substr($last, -3);
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . str_pad($newNum, 3, '0', STR_PAD_LEFT);
}

function handleFileUpload($file, $type) {
    $uploadDir = __DIR__ . '/../uploads/customer_documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    
    if (!in_array($ext, $allowed)) {
        throw new Exception('Invalid file type. Only JPG, PNG, and PDF allowed.');
    }
    
    $filename = $type . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }
    
    return 'uploads/customer_documents/' . $filename;
}
?>
