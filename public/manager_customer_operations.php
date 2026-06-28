<?php
/**
 * MANAGER CUSTOMER OPERATIONS API
 * All queries verified against live DB schema
 */

ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
ob_end_clean();

require_login();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':         listCustomers();   break;
        case 'view':         viewCustomer();    break;
        case 'add':          addCustomer();     break;
        case 'update':       updateCustomer();  break;
        case 'verify':       verifyCustomer();  break;
        case 'log_download': logDownload();     break;
        default:             echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────
// LIST CUSTOMERS
// ─────────────────────────────────────────────────────────────────────
function listCustomers() {
    global $pdo, $station_id;

    // Check table exists
    try { $pdo->query("SELECT 1 FROM customers LIMIT 1"); }
    catch (Exception $e) {
        echo json_encode(['success' => true, 'customers' => [], 'stats' => emptyStats()]);
        return;
    }

    $search       = trim($_GET['search'] ?? '');
    $type         = trim($_GET['type'] ?? '');
    $status       = trim($_GET['status'] ?? '');
    $verification = trim($_GET['verification'] ?? '');
    $payment      = trim($_GET['payment'] ?? '');
    $dateFrom     = trim($_GET['date_from'] ?? '');
    $dateTo       = trim($_GET['date_to'] ?? '');

    $where  = ['c.station_id = ?'];
    $params = [$station_id];

    if ($search !== '') {
        $where[] = "(c.customer_id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.name LIKE ? OR c.contact_number LIKE ? OR c.company_name LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s, $s, $s);
    }

    if ($type !== '') {
        $where[] = "c.customer_type = ?";
        $params[] = $type;
    }

    if ($status !== '') {
        $where[] = "c.status = ?";
        $params[] = $status;
    }

    if ($verification !== '') {
        $where[] = "c.verification_status = ?";
        $params[] = $verification;
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.customer_id,
            COALESCE(NULLIF(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.middle_name,''),' ',COALESCE(c.last_name,'')), '  '), c.name) AS display_name,
            c.first_name,
            c.middle_name,
            c.last_name,
            c.name,
            c.contact_number,
            c.customer_type,
            c.status,
            c.verification_status,
            c.outstanding_balance,
            COALESCE(c.credit_limit, 0) AS credit_limit,
            c.company_name,
            c.gov_id_image,
            c.cr_document,
            COALESCE(c.registered_at, c.created_at) AS registered_at
        FROM customers c
        WHERE $whereClause
        ORDER BY COALESCE(c.registered_at, c.created_at) DESC
    ");
    $stmt->execute($params);
    $allCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch per-customer transaction stats + apply payment/date filters
    $filteredCustomers = [];
    foreach ($allCustomers as $c) {
        $lastTxDate  = null;
        $totalSpent  = 0.0;

        // Fuel
        try {
            $q = $pdo->prepare("SELECT MAX(transaction_date) AS last_d, COALESCE(SUM(total_amount),0) AS tot
                                 FROM fuel_transactions WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $station_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['last_d']) { $lastTxDate = max($lastTxDate, $r['last_d']); $totalSpent += (float)$r['tot']; }
        } catch (Exception $e) {}

        // Merchandise
        try {
            $q = $pdo->prepare("SELECT MAX(transaction_date) AS last_d, COALESCE(SUM(total_amount),0) AS tot
                                 FROM merchandise_transactions WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $station_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['last_d']) { $lastTxDate = max($lastTxDate, $r['last_d']); $totalSpent += (float)$r['tot']; }
        } catch (Exception $e) {}

        // Job Orders
        try {
            $q = $pdo->prepare("SELECT MAX(created_at) AS last_d, COALESCE(SUM(total_cost),0) AS tot
                                 FROM job_orders WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $station_id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['last_d']) { $lastTxDate = max($lastTxDate, $r['last_d']); $totalSpent += (float)$r['tot']; }
        } catch (Exception $e) {}

        $ob = (float)($c['outstanding_balance'] ?? 0);
        if ($ob <= 0) {
            $payStatus = 'paid';
        } elseif ($totalSpent > 0 && $ob < $totalSpent) {
            $payStatus = 'partial';
        } else {
            $payStatus = 'unpaid';
        }

        // Payment filter
        if ($payment !== '' && $payStatus !== $payment) continue;

        // Date filter
        $regDate = date('Y-m-d', strtotime($c['registered_at'] ?? 'now'));
        if ($dateFrom !== '' && $regDate < $dateFrom) continue;
        if ($dateTo   !== '' && $regDate > $dateTo)   continue;

        $c['last_transaction'] = $lastTxDate;
        $c['total_spent']      = $totalSpent;
        $c['payment_status']   = $payStatus;
        $filteredCustomers[]   = $c;
    }

    // Stats from ALL customers in station (unfiltered)
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                             AS total,
            SUM(CASE WHEN DATE(COALESCE(registered_at,created_at)) = CURDATE() THEN 1 ELSE 0 END) AS new_today,
            SUM(CASE WHEN customer_type = 'regular' THEN 1 ELSE 0 END)          AS regular,
            SUM(CASE WHEN customer_type = 'fleet'   THEN 1 ELSE 0 END)          AS fleet,
            SUM(CASE WHEN outstanding_balance > 0   THEN 1 ELSE 0 END)          AS outstanding,
            SUM(CASE WHEN status = 'active'         THEN 1 ELSE 0 END)          AS active
        FROM customers WHERE station_id = ?
    ");
    $statsStmt->execute([$station_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: emptyStats();

    echo json_encode(['success' => true, 'customers' => $filteredCustomers, 'stats' => $stats]);
}

function emptyStats() {
    return ['total' => 0, 'new_today' => 0, 'regular' => 0, 'fleet' => 0, 'outstanding' => 0, 'active' => 0];
}

// ─────────────────────────────────────────────────────────────────────
// VIEW SINGLE CUSTOMER
// ─────────────────────────────────────────────────────────────────────
function viewCustomer() {
    global $pdo, $station_id;

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('Customer ID required');

    $stmt = $pdo->prepare("
        SELECT c.*,
               CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS registered_by_name,
               CONCAT(COALESCE(v.first_name,''), ' ', COALESCE(v.last_name,'')) AS verified_by_name
        FROM customers c
        LEFT JOIN users u ON c.registered_by = u.id
        LEFT JOIN users v ON c.verified_by   = v.id
        WHERE c.id = ? AND c.station_id = ?
    ");
    $stmt->execute([$id, $station_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) throw new Exception('Customer not found');

    $txStats = [
        'fuel_count' => 0, 'fuel_amount' => 0,
        'merch_count' => 0, 'merch_amount' => 0,
        'service_count' => 0, 'service_amount' => 0,
        'total_count' => 0, 'total_amount' => 0,
        'last_transaction' => null
    ];
    $history = [];

    // ── Fuel ─────────────────────────────────────────────────────────
    try {
        $q = $pdo->prepare("
            SELECT transaction_date AS txn_date,
                   transaction_id   AS reference_no,
                   'Fuel'           AS module,
                   CONCAT(fuel_type, ' — ', liters_sold, 'L') AS description,
                   total_amount     AS amount,
                   COALESCE(status,'completed') AS txn_status
            FROM fuel_transactions
            WHERE customer_id = ? AND station_id = ?
            ORDER BY transaction_date DESC
            LIMIT 50
        ");
        $q->execute([$id, $station_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $history[] = $row;
            $txStats['fuel_count']++;
            $txStats['fuel_amount'] += (float)$row['amount'];
        }
    } catch (Exception $e) {}

    // ── Merchandise ──────────────────────────────────────────────────
    try {
        $q = $pdo->prepare("
            SELECT transaction_date AS txn_date,
                   transaction_id   AS reference_no,
                   'Merchandise'    AS module,
                   CONCAT('Sale — ₱', FORMAT(total_amount,2)) AS description,
                   total_amount     AS amount,
                   COALESCE(validation_status,'completed') AS txn_status
            FROM merchandise_transactions
            WHERE customer_id = ? AND station_id = ?
            ORDER BY transaction_date DESC
            LIMIT 50
        ");
        $q->execute([$id, $station_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $history[] = $row;
            $txStats['merch_count']++;
            $txStats['merch_amount'] += (float)$row['amount'];
        }
    } catch (Exception $e) {}

    // ── Job Orders ───────────────────────────────────────────────────
    try {
        $q = $pdo->prepare("
            SELECT created_at  AS txn_date,
                   COALESCE(job_order_id, job_order_number, CONCAT('JO-', id)) AS reference_no,
                   'Job Order' AS module,
                   COALESCE(service_type,'Service') AS description,
                   total_cost  AS amount,
                   COALESCE(status,'pending') AS txn_status
            FROM job_orders
            WHERE customer_id = ? AND station_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $q->execute([$id, $station_id]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $history[] = $row;
            $txStats['service_count']++;
            $txStats['service_amount'] += (float)$row['amount'];
        }
    } catch (Exception $e) {}

    // Sort by date desc
    usort($history, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
    $history = array_slice($history, 0, 20);

    $txStats['total_count']  = $txStats['fuel_count'] + $txStats['merch_count'] + $txStats['service_count'];
    $txStats['total_amount'] = $txStats['fuel_amount'] + $txStats['merch_amount'] + $txStats['service_amount'];
    $txStats['last_transaction'] = !empty($history) ? $history[0]['txn_date'] : null;

    $ob = (float)($customer['outstanding_balance'] ?? 0);
    $totalSpent = $txStats['total_amount'];
    $totalPayments = max(0, $totalSpent - $ob);

    if ($ob <= 0)                                           $payStatus = 'paid';
    elseif ($totalSpent > 0 && $ob < $totalSpent)          $payStatus = 'partial';
    else                                                    $payStatus = 'unpaid';

    $financials = [
        'outstanding_balance' => $ob,
        'credit_limit'        => (float)($customer['credit_limit'] ?? 0),
        'total_payments'      => $totalPayments,
        'remaining_balance'   => $ob,
        'payment_status'      => $payStatus,
        'last_payment_date'   => $txStats['last_transaction']
    ];

    echo json_encode([
        'success'             => true,
        'customer'            => $customer,
        'transactions'        => $txStats,
        'financials'          => $financials,
        'transaction_history' => $history
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// ADD CUSTOMER
// ─────────────────────────────────────────────────────────────────────
function addCustomer() {
    global $pdo, $station_id, $me;

    $firstName   = trim($_POST['first_name']   ?? '');
    $middleName  = trim($_POST['middle_name']  ?? '');
    $lastName    = trim($_POST['last_name']    ?? '');
    $contactNo   = trim($_POST['contact_number'] ?? '');
    $address     = trim($_POST['address']      ?? '');
    $custType    = $_POST['customer_type'] ?? 'walk-in';
    $statusVal   = $_POST['status']        ?? 'active';
    $govIdType   = trim($_POST['gov_id_type'] ?? '') ?: null;
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);
    $outstanding = (float)($_POST['outstanding_balance'] ?? 0);

    $companyName          = trim($_POST['company_name']           ?? '') ?: null;
    $companyAddress       = trim($_POST['company_address']        ?? '') ?: null;
    $companyContactPerson = trim($_POST['company_contact_person'] ?? '') ?: null;
    $companyContactNumber = trim($_POST['company_contact_number'] ?? '') ?: null;

    if (!$firstName || !$lastName || !$contactNo || !$address) {
        throw new Exception('First name, last name, contact number, and address are required.');
    }

    $govIdImage  = null;
    $crDocument  = null;

    if (!empty($_FILES['gov_id_image']['name'])) {
        $govIdImage = handleFileUpload($_FILES['gov_id_image'], 'gov_id');
    }
    if (!empty($_FILES['cr_document']['name'])) {
        $crDocument = handleFileUpload($_FILES['cr_document'], 'cr');
    }

    $customerId = generateCustomerId($station_id);
    $fullName   = trim("$firstName $middleName $lastName");

    $stmt = $pdo->prepare("
        INSERT INTO customers (
            customer_id, station_id,
            name, first_name, middle_name, last_name,
            contact_number, address, customer_type, status,
            gov_id_type, gov_id_image, cr_document,
            company_name, company_address, company_contact_person, company_contact_number,
            credit_limit, outstanding_balance, verification_status,
            registered_by, registered_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $stmt->execute([
        $customerId, $station_id,
        $fullName, $firstName, $middleName, $lastName,
        $contactNo, $address, $custType, $statusVal,
        $govIdType, $govIdImage, $crDocument,
        $companyName, $companyAddress, $companyContactPerson, $companyContactNumber,
        $creditLimit, $outstanding,
        $me['id']
    ]);

    $newId = $pdo->lastInsertId();
    write_audit_log($pdo, 'Create', "New customer: $fullName ($customerId)", 'customers', $newId, 'customer');

    echo json_encode(['success' => true, 'message' => "Customer $customerId created successfully!", 'customer_id' => $customerId, 'id' => $newId]);
}

// ─────────────────────────────────────────────────────────────────────
// UPDATE CUSTOMER
// ─────────────────────────────────────────────────────────────────────
function updateCustomer() {
    global $pdo, $station_id, $me;

    $id          = (int)($_POST['customer_id'] ?? 0);
    if (!$id) throw new Exception('Customer ID required');

    $firstName   = trim($_POST['first_name']   ?? '');
    $middleName  = trim($_POST['middle_name']  ?? '');
    $lastName    = trim($_POST['last_name']    ?? '');
    $contactNo   = trim($_POST['contact_number'] ?? '');
    $address     = trim($_POST['address']      ?? '');
    $custType    = $_POST['customer_type'] ?? 'walk-in';
    $statusVal   = $_POST['status']        ?? 'active';
    $govIdType   = trim($_POST['gov_id_type'] ?? '') ?: null;
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);

    $companyName          = trim($_POST['company_name']           ?? '') ?: null;
    $companyAddress       = trim($_POST['company_address']        ?? '') ?: null;
    $companyContactPerson = trim($_POST['company_contact_person'] ?? '') ?: null;
    $companyContactNumber = trim($_POST['company_contact_number'] ?? '') ?: null;

    if (!$firstName || !$lastName || !$contactNo || !$address) {
        throw new Exception('Required fields are missing');
    }

    // Verify ownership
    $chk = $pdo->prepare("SELECT customer_id FROM customers WHERE id = ? AND station_id = ?");
    $chk->execute([$id, $station_id]);
    if (!$chk->fetch()) throw new Exception('Customer not found');

    $fullName = trim("$firstName $middleName $lastName");

    $fields = [
        "name = ?", "first_name = ?", "middle_name = ?", "last_name = ?",
        "contact_number = ?", "address = ?", "customer_type = ?", "status = ?",
        "gov_id_type = ?",
        "company_name = ?", "company_address = ?",
        "company_contact_person = ?", "company_contact_number = ?",
        "credit_limit = ?",
        "updated_by = ?", "updated_at = NOW()"
    ];
    $params = [
        $fullName, $firstName, $middleName, $lastName,
        $contactNo, $address, $custType, $statusVal,
        $govIdType,
        $companyName, $companyAddress,
        $companyContactPerson, $companyContactNumber,
        $creditLimit,
        $me['id']
    ];

    if (!empty($_FILES['gov_id_image']['name'])) {
        $img = handleFileUpload($_FILES['gov_id_image'], 'gov_id');
        $fields[] = "gov_id_image = ?";
        $params[]  = $img;
    }
    if (!empty($_FILES['cr_document']['name'])) {
        $cr = handleFileUpload($_FILES['cr_document'], 'cr');
        $fields[] = "cr_document = ?";
        $params[]  = $cr;
    }

    $params[] = $id;
    $params[] = $station_id;

    $pdo->prepare("UPDATE customers SET " . implode(', ', $fields) . " WHERE id = ? AND station_id = ?")
        ->execute($params);

    write_audit_log($pdo, 'Update', "Updated customer: $fullName (ID: $id)", 'customers', $id, 'customer');
    echo json_encode(['success' => true, 'message' => 'Customer updated successfully!']);
}

// ─────────────────────────────────────────────────────────────────────
// VERIFY CUSTOMER
// ─────────────────────────────────────────────────────────────────────
function verifyCustomer() {
    global $pdo, $station_id, $me;

    $id      = (int)($_POST['id']     ?? 0);
    $status  = $_POST['status']   ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$id || !in_array($status, ['verified', 'rejected'])) {
        throw new Exception('Invalid verification parameters');
    }

    $chk = $pdo->prepare("SELECT customer_id, name, first_name, last_name FROM customers WHERE id = ? AND station_id = ?");
    $chk->execute([$id, $station_id]);
    $cust = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$cust) throw new Exception('Customer not found');

    $pdo->prepare("
        UPDATE customers
        SET verification_status = ?, verified_by = ?, verified_at = NOW(), verification_remarks = ?
        WHERE id = ? AND station_id = ?
    ")->execute([$status, $me['id'], $remarks, $id, $station_id]);

    $fullName = trim(($cust['first_name'] ?: $cust['name']) . ' ' . $cust['last_name']);
    $label    = strtoupper($status);
    write_audit_log($pdo, 'Verify', "Customer $fullName ({$cust['customer_id']}) set to $label. Remarks: $remarks", 'customers', $id, 'customer');

    echo json_encode(['success' => true, 'message' => "Customer has been $status successfully."]);
}

// ─────────────────────────────────────────────────────────────────────
// LOG DOCUMENT DOWNLOAD
// ─────────────────────────────────────────────────────────────────────
function logDownload() {
    global $pdo, $station_id;

    $id      = (int)($_GET['id']       ?? 0);
    $docType = $_GET['doc_type'] ?? '';

    if (!$id || !in_array($docType, ['gov_id', 'cr'])) {
        throw new Exception('Invalid parameters');
    }

    $chk = $pdo->prepare("SELECT customer_id FROM customers WHERE id = ? AND station_id = ?");
    $chk->execute([$id, $station_id]);
    $custId = $chk->fetchColumn();

    if ($custId) {
        write_audit_log($pdo, 'Download', "Downloaded $docType document for customer: $custId", 'customers', $id, 'customer');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Customer not found']);
    }
}

// ─────────────────────────────────────────────────────────────────────
// GENERATE CUSTOMER ID
// ─────────────────────────────────────────────────────────────────────
function generateCustomerId($stationId) {
    global $pdo;
    $prefix = "CUS-{$stationId}-" . date('Ym') . "-";
    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -3) + 1) : 1;
    return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

// ─────────────────────────────────────────────────────────────────────
// HANDLE FILE UPLOAD
// ─────────────────────────────────────────────────────────────────────
function handleFileUpload($file, $type) {
    $uploadDir = __DIR__ . '/../uploads/customer_documents/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($ext, $allowed)) throw new Exception("Invalid file type. Allowed: JPG, PNG, PDF.");
    if ($file['size'] > 5 * 1024 * 1024) throw new Exception("File too large. Max 5MB.");
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload error: " . $file['error']);

    $filename = $type . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        throw new Exception("Failed to save uploaded file.");
    }
    return 'uploads/customer_documents/' . $filename;
}
?>
