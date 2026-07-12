<?php
/**
 * MANAGER CUSTOMER OPERATIONS API
 * All queries verified against live DB schema
 */

ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Your account is not assigned to a station.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // ── Manager Permission Boundary ──────────────────────────────────
    // The following actions are EXPLICITLY DENIED for the manager role.
    // Even if a request is crafted manually, these will return a 403.
    $denied_actions = ['delete', 'restore', 'permanent_delete', 'reverse_transaction',
                       'manual_balance', 'manage_permissions', 'audit_logs'];
    if (in_array($action, $denied_actions)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Access denied. Managers are not permitted to perform this action.'
        ]);
        exit;
    }

    switch ($action) {
        case 'list':                listCustomers();          break;
        case 'view':                viewCustomer();           break;
        case 'add':                 addCustomer();            break;
        case 'update':              updateCustomer();         break;
        case 'verify':              verifyCustomer();         break;
        case 'log_download':        logDownload();            break;
        case 'transaction_history': getTransactionHistory();  break;
        default:                    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────
// LIST CUSTOMERS
// ─────────────────────────────────────────────────────────────────────
function listCustomers() {
    global $pdo, $station_id, $role;

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

    $where  = [];
    $params = [];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    $customerIdExpr = customer_id_expr($pdo, 'c');
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $firstNameExpr = customer_first_name_expr($pdo, 'c');
    $middleNameExpr = customer_middle_name_expr($pdo, 'c');
    $lastNameExpr = customer_last_name_expr($pdo, 'c');
    $contactExpr = customer_contact_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $statusExpr = customer_status_expr($pdo, 'c');
    $registeredExpr = customer_registered_at_expr($pdo, 'c');
    $balanceExpr = customer_balance_expr($pdo, 'c');
    $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
    $verificationExpr = customer_verification_status_expr($pdo, 'c');
    $govIdTypeExpr = customer_gov_id_type_expr($pdo, 'c');

    if ($search !== '') {
        $where[] = "($customerIdExpr LIKE ? OR $displayNameExpr LIKE ? OR $contactExpr LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s);
    }

    if ($type !== '' && $type !== 'registered') { $type = ''; }

    if ($status !== '') {
        $where[] = "$statusExpr = ?";
        $params[] = $status;
    }

    if ($verification !== '') {
        $where[] = "$verificationExpr = ?";
        $params[] = $verification;
    }
    if ($dateFrom !== '') {
        $where[] = "DATE($registeredExpr) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE($registeredExpr) <= ?";
        $params[] = $dateTo;
    }

    $whereClause = $where ? implode(' AND ', $where) : '1=1';

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.station_id,
            $customerIdExpr AS customer_id,
            $displayNameExpr AS display_name,
            $firstNameExpr AS first_name,
            $middleNameExpr AS middle_name,
            $lastNameExpr AS last_name,
            $displayNameExpr AS name,
            $contactExpr AS contact_number,
            $typeExpr AS customer_type,
            $statusExpr AS status,
            $verificationExpr AS verification_status,
            $balanceExpr AS outstanding_balance,
            $creditLimitExpr AS credit_limit,
            " . customer_company_expr($pdo, 'company_name', 'c') . " AS company_name,
            " . customer_company_expr($pdo, 'company_address', 'c') . " AS company_address,
            " . customer_company_expr($pdo, 'company_contact_person', 'c') . " AS company_contact_person,
            " . customer_company_expr($pdo, 'company_contact_number', 'c') . " AS company_contact_number,
            $govIdTypeExpr AS gov_id_type,
            " . customer_verification_remarks_expr($pdo, 'c') . " AS verification_remarks,
            c.gov_id_image,
            c.cr_document,
            $registeredExpr AS registered_at
        FROM customers c
        WHERE $whereClause
        ORDER BY $registeredExpr DESC, c.id DESC
    ");
    $stmt->execute($params);
    $allCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch per-customer transaction stats + apply payment/date filters
    $filteredCustomers = [];
    foreach ($allCustomers as $c) {
        $lastTxDate  = null;
        $totalSpent  = 0.0;
        $txStation = customer_can_view_all_stations($role) ? (int)($c['station_id'] ?? 0) : $station_id;

        // Fuel
        try {
            $q = $pdo->prepare("SELECT MAX(transaction_date) AS last_d, COALESCE(SUM(total_amount),0) AS tot
                                 FROM fuel_transactions WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $txStation]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['last_d']) { $lastTxDate = max($lastTxDate, $r['last_d']); $totalSpent += (float)$r['tot']; }
        } catch (Exception $e) {}

        // Merchandise
        try {
            $q = $pdo->prepare("SELECT MAX(transaction_date) AS last_d, COALESCE(SUM(total_amount),0) AS tot
                                 FROM merchandise_transactions WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $txStation]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['last_d']) { $lastTxDate = max($lastTxDate, $r['last_d']); $totalSpent += (float)$r['tot']; }
        } catch (Exception $e) {}

        // Job Orders
        try {
            $q = $pdo->prepare("SELECT MAX(created_at) AS last_d, COALESCE(SUM(total_cost),0) AS tot
                                 FROM job_orders WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $txStation]);
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

        $c['last_transaction'] = $lastTxDate;
        $c['total_spent']      = $totalSpent;
        $c['payment_status']   = $payStatus;
        $filteredCustomers[]   = $c;
    }

    // Stats from all visible customers (unfiltered)
    $statsWhere = [];
    $statsParams = [];
    customer_apply_station_scope($statsWhere, $statsParams, 'c', $role, $station_id);
    $statsWc = $statsWhere ? implode(' AND ', $statsWhere) : '1=1';
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                             AS total,
            SUM(CASE WHEN DATE($registeredExpr) = CURDATE() THEN 1 ELSE 0 END) AS new_today,
            COUNT(*)                                                           AS registered,
            SUM(CASE WHEN $verificationExpr = 'pending' THEN 1 ELSE 0 END)     AS pending_verification,
            SUM(CASE WHEN $balanceExpr > 0 THEN 1 ELSE 0 END)                  AS outstanding,
            SUM(CASE WHEN $statusExpr = 'active' THEN 1 ELSE 0 END)            AS active
        FROM customers c WHERE $statsWc
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: emptyStats();

    echo json_encode(['success' => true, 'customers' => $filteredCustomers, 'stats' => $stats]);
}

function emptyStats() {
    return ['total' => 0, 'new_today' => 0, 'registered' => 0, 'pending_verification' => 0, 'outstanding' => 0, 'active' => 0];
}

// ─────────────────────────────────────────────────────────────────────
// VIEW SINGLE CUSTOMER
// ─────────────────────────────────────────────────────────────────────
function viewCustomer() {
    global $pdo, $station_id, $role;

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('Customer ID required');

    $customerIdExpr = customer_id_expr($pdo, 'c');
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $firstNameExpr = customer_first_name_expr($pdo, 'c');
    $middleNameExpr = customer_middle_name_expr($pdo, 'c');
    $lastNameExpr = customer_last_name_expr($pdo, 'c');
    $contactExpr = customer_contact_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $statusExpr = customer_status_expr($pdo, 'c');
    $registeredExpr = customer_registered_at_expr($pdo, 'c');
    $balanceExpr = customer_balance_expr($pdo, 'c');
    $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
    $verificationExpr = customer_verification_status_expr($pdo, 'c');
    $govIdTypeExpr = customer_gov_id_type_expr($pdo, 'c');

    $where = ['c.id = ?'];
    $params = [$id];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    $stmt = $pdo->prepare("
        SELECT c.*,
               $customerIdExpr AS customer_id,
               $displayNameExpr AS display_name,
               $firstNameExpr AS first_name,
               $middleNameExpr AS middle_name,
               $lastNameExpr AS last_name,
               $contactExpr AS contact_number,
               $typeExpr AS customer_type,
               $statusExpr AS status,
               $registeredExpr AS registered_at,
               $balanceExpr AS outstanding_balance,
               $creditLimitExpr AS credit_limit,
               $verificationExpr AS verification_status,
               $govIdTypeExpr AS gov_id_type,
               " . customer_company_expr($pdo, 'company_name', 'c') . " AS company_name,
               " . customer_company_expr($pdo, 'company_address', 'c') . " AS company_address,
               " . customer_company_expr($pdo, 'company_contact_person', 'c') . " AS company_contact_person,
               " . customer_company_expr($pdo, 'company_contact_number', 'c') . " AS company_contact_number,
               " . customer_verification_remarks_expr($pdo, 'c') . " AS verification_remarks,
               " . customer_user_name_expr('u') . " AS registered_by_name,
               " . customer_user_name_expr('v') . " AS verified_by_name
        FROM customers c
        LEFT JOIN users u ON c.registered_by = u.id
        LEFT JOIN users v ON c.verified_by   = v.id
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) throw new Exception('Customer not found');

    $customerStation = customer_can_view_all_stations($role) ? (int)($customer['station_id'] ?? 0) : $station_id;

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
        $q->execute([$id, $customerStation]);
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
                   CONCAT('Sale — \u{20B1}', FORMAT(total_amount,2)) AS description,
                   total_amount     AS amount,
                   COALESCE(validation_status,'completed') AS txn_status
            FROM merchandise_transactions
            WHERE customer_id = ? AND station_id = ?
            ORDER BY transaction_date DESC
            LIMIT 50
        ");
        $q->execute([$id, $customerStation]);
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
        $q->execute([$id, $customerStation]);
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

    if ($station_id <= 0) {
        throw new Exception('A station assignment is required before adding customers.');
    }

    $firstName   = trim($_POST['first_name']   ?? '');
    $middleName  = trim($_POST['middle_name']  ?? '');
    $lastName    = trim($_POST['last_name']    ?? '');
    $contactNo   = trim($_POST['contact_number'] ?? '');
    $address     = trim($_POST['address']      ?? '');
    $custType    = 'regular';
    $statusVal   = $_POST['status']        ?? 'active';
    $govIdType   = trim($_POST['gov_id_type'] ?? '') ?: null;
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);
    // NOTE: Managers are NOT permitted to manually set or edit outstanding_balance.
    // Balance is system-managed through transactions only.

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

    // outstanding_balance starts at 0 — managers cannot set it manually.
    $newId = customer_insert_existing($pdo, [
        'customer_id'            => $customerId,
        'station_id'             => $station_id,
        'name'                   => $fullName,
        'first_name'             => $firstName,
        'middle_name'            => $middleName,
        'last_name'              => $lastName,
        'contact_number'         => $contactNo,
        'phone'                  => $contactNo,
        'address'                => $address,
        'customer_type'          => $custType,
        'type'                   => customer_legacy_billing_type($custType),
        'status'                 => $statusVal,
        'account_status'         => $statusVal,
        'gov_id_type'            => $govIdType,
        'id_type'                => $govIdType,
        'gov_id_image'           => $govIdImage,
        'cr_document'            => $crDocument,
        'company_name'           => $companyName,
        'company_address'        => $companyAddress,
        'company_contact_person' => $companyContactPerson,
        'contact_person'         => $companyContactPerson,
        'company_contact_number' => $companyContactNumber,
        'credit_limit'           => $creditLimit,
        'current_balance'        => 0,
        'balance'                => 0,
        'verification_status'    => 'pending',
        'registered_by'          => $me['id'] ?? null,
    ], [
        'registered_at'          => 'NOW()',
        'created_at'             => 'NOW()',
    ]);
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
    $custType    = 'regular';
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

    $values = [
        'name'                   => $fullName,
        'first_name'             => $firstName,
        'middle_name'            => $middleName,
        'last_name'              => $lastName,
        'contact_number'         => $contactNo,
        'phone'                  => $contactNo,
        'address'                => $address,
        'customer_type'          => $custType,
        'type'                   => customer_legacy_billing_type($custType),
        'status'                 => $statusVal,
        'account_status'         => $statusVal,
        'gov_id_type'            => $govIdType,
        'id_type'                => $govIdType,
        'company_name'           => $companyName,
        'company_address'        => $companyAddress,
        'company_contact_person' => $companyContactPerson,
        'contact_person'         => $companyContactPerson,
        'company_contact_number' => $companyContactNumber,
        'credit_limit'           => $creditLimit,
        'updated_by'             => $me['id'] ?? null,
    ];

    if (!empty($_FILES['gov_id_image']['name'])) {
        $img = handleFileUpload($_FILES['gov_id_image'], 'gov_id');
        $values['gov_id_image'] = $img;
    }
    if (!empty($_FILES['cr_document']['name'])) {
        $cr = handleFileUpload($_FILES['cr_document'], 'cr');
        $values['cr_document'] = $cr;
    }

    customer_update_existing($pdo, $values, 'id = ? AND station_id = ?', [$id, $station_id], [
        'updated_at' => 'NOW()',
    ]);

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

    customer_update_existing($pdo, [
        'verification_status'  => $status,
        'mgr_status'           => $status,
        'verified_by'          => $me['id'] ?? null,
        'verification_remarks' => $remarks,
        'mgr_notes'            => $remarks,
        'mgr_reviewed_by'      => $me['id'] ?? null,
    ], 'id = ? AND station_id = ?', [$id, $station_id], [
        'verified_at'          => 'NOW()',
        'mgr_reviewed_at'      => 'NOW()',
        'updated_at'           => 'NOW()',
    ]);

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

// ─────────────────────────────────────────────────────────────────────
// TRANSACTION HISTORY (PAGINATED & FILTERABLE)
// ─────────────────────────────────────────────────────────────────────
function getTransactionHistory() {
    global $pdo, $station_id, $role;

    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        echo json_encode(['success' => false, 'error' => 'Customer ID is required']);
        return;
    }

    $scopeWhere = ['c.id = ?'];
    $scopeParams = [$customerId];
    customer_apply_station_scope($scopeWhere, $scopeParams, 'c', $role, $station_id);
    $scopeStmt = $pdo->prepare("SELECT c.station_id FROM customers c WHERE " . implode(' AND ', $scopeWhere));
    $scopeStmt->execute($scopeParams);
    $customerStation = (int)$scopeStmt->fetchColumn();
    if ($customerStation <= 0) {
        echo json_encode(['success' => false, 'error' => 'Customer not found']);
        return;
    }

    $search        = trim($_GET['search'] ?? '');
    $module        = trim($_GET['module'] ?? '');
    $txnStatus     = trim($_GET['txn_status'] ?? '');
    $paymentStatus = trim($_GET['payment_status'] ?? '');
    $dateFrom      = trim($_GET['date_from'] ?? '');
    $dateTo        = trim($_GET['date_to'] ?? '');

    $unionSql = "
        SELECT
            ft.id AS source_id,
            ft.transaction_date AS txn_date,
            ft.transaction_id AS reference_no,
            'Fuel' AS module,
            CONCAT(ft.fuel_type, ' — ', ft.liters_sold, 'L') AS description,
            ft.total_amount AS amount,
            CASE WHEN LOWER(ft.status) = 'completed' THEN 'paid' ELSE 'pending' END AS payment_status,
            ft.status AS txn_status,
            COALESCE(u.name, 'Staff') AS processed_by,
            ft.customer_id,
            ft.station_id
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id

        UNION ALL

        SELECT
            mt.id AS source_id,
            mt.transaction_date AS txn_date,
            mt.transaction_id AS reference_no,
            'Merchandise' AS module,
            CONCAT('Sale — \u{20B1}', FORMAT(mt.total_amount,2)) AS description,
            mt.total_amount AS amount,
            COALESCE(mt.payment_status, 'pending') AS payment_status,
            mt.validation_status AS txn_status,
            COALESCE(u.name, 'Staff') AS processed_by,
            mt.customer_id,
            mt.station_id
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id

        UNION ALL

        SELECT
            jo.id AS source_id,
            jo.created_at AS txn_date,
            COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS reference_no,
            'Job Order' AS module,
            COALESCE(jo.service_type, 'Service') AS description,
            jo.total_cost AS amount,
            COALESCE(jo.payment_status, 'pending') AS payment_status,
            jo.status AS txn_status,
            COALESCE(u.name, 'Staff') AS processed_by,
            jo.customer_id,
            jo.station_id
        FROM job_orders jo
        LEFT JOIN users u ON jo.created_by = u.id
    ";

    $where = ["t.customer_id = :customer_id", "t.station_id = :station_id"];
    $binds = [
        ':customer_id' => $customerId,
        ':station_id'  => $customerStation
    ];

    if ($search !== '') {
        $where[] = "t.reference_no LIKE :search";
        $binds[':search'] = "%$search%";
    }
    if ($module !== '') {
        $where[] = "t.module = :module";
        $binds[':module'] = $module;
    }
    if ($txnStatus !== '') {
        $where[] = "LOWER(t.txn_status) = :txn_status";
        $binds[':txn_status'] = strtolower($txnStatus);
    }
    if ($paymentStatus !== '') {
        $where[] = "LOWER(t.payment_status) = :payment_status";
        $binds[':payment_status'] = strtolower($paymentStatus);
    }
    if ($dateFrom !== '') {
        $where[] = "DATE(t.txn_date) >= :date_from";
        $binds[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE(t.txn_date) <= :date_to";
        $binds[':date_to'] = $dateTo;
    }

    $whereClause = implode(' AND ', $where);

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($unionSql) t WHERE $whereClause");
        foreach ($binds as $key => $val) {
            $countStmt->bindValue($key, $val);
        }
        $countStmt->execute();
        $totalRows = (int)$countStmt->fetchColumn();

        $limit = (int)($_GET['limit'] ?? 10);
        $page = (int)($_GET['page'] ?? 1);
        $offset = ($page - 1) * $limit;

        $dataStmt = $pdo->prepare("
            SELECT t.* FROM ($unionSql) t
            WHERE $whereClause
            ORDER BY t.txn_date DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($binds as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'total'   => $totalRows,
            'page'    => $page,
            'limit'   => $limit,
            'data'    => $rows
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>
