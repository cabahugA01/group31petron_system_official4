<?php
/**
 * STAFF CUSTOMER OPERATIONS API
 */
ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);

if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Your account is not assigned to a station.']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Staff permission gate: block all forbidden operations ────────────────────
$STAFF_BLOCKED_ACTIONS = ['delete','remove','archive','restore','verify','toggle_status',
                           'update_documents','upload_doc','delete_doc','view_document',
                           'audit_log','get_audit'];
if (in_array($action, $STAFF_BLOCKED_ACTIONS)) {
    echo json_encode(['success' => false, 'error' => 'Action not permitted for staff role']); exit;
}

try {
    switch ($action) {
        case 'list': case 'get_customers': listCustomers(); break;
        case 'view': case 'get_customer':  viewCustomer();  break;
        case 'add':  case 'add_customer':  addCustomer();   break;
        case 'update': case 'update_customer': updateCustomer(); break;
        case 'transactions': getTransactions(); break;
        default: echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("[Customer API] Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function listCustomers() {
    global $pdo, $station_id, $role;

    try { $pdo->query("SELECT 1 FROM customers LIMIT 1"); }
    catch (Exception $e) {
        echo json_encode(['success' => true, 'customers' => [], 'stats' => ['total'=>0,'new_today'=>0,'registered'=>0,'active'=>0]]);
        return;
    }

    $search   = trim($_GET['search']    ?? '');
    $type     = trim($_GET['type']      ?? '');
    $status   = trim($_GET['status']    ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to']   ?? '');

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

    if ($search !== '') {
        $where[]  = "($customerIdExpr LIKE ? OR $displayNameExpr LIKE ? OR $contactExpr LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s);
    }
    if ($type !== '' && $type !== 'registered') { $type = ''; }
    if ($status !== '') { $where[] = "$statusExpr = ?";     $params[] = $status; }
    if ($dateFrom !== '') { $where[] = "DATE($registeredExpr) >= ?"; $params[] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE($registeredExpr) <= ?"; $params[] = $dateTo;   }

    $wc = $where ? implode(' AND ', $where) : '1=1';

    $stmt = $pdo->prepare("
        SELECT c.id,
            $customerIdExpr AS customer_id,
            $displayNameExpr AS display_name,
            $firstNameExpr AS first_name,
            $middleNameExpr AS middle_name,
            $lastNameExpr AS last_name,
            $contactExpr AS contact_number,
            COALESCE(c.address,'') AS address,
            $typeExpr AS customer_type,
            $statusExpr AS status,
            $registeredExpr AS registered_at,
            (
                (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND mt.station_id = c.station_id) +
                (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = c.id AND jo.station_id = c.station_id) +
                (SELECT COUNT(*) FROM fuel_transactions ft WHERE ft.customer_id = c.id AND ft.station_id = c.station_id)
            ) AS total_transactions,
            NULLIF(GREATEST(
                COALESCE((SELECT MAX(COALESCE(transaction_date,created_at)) FROM merchandise_transactions WHERE customer_id=c.id AND station_id=c.station_id),'2000-01-01'),
                COALESCE((SELECT MAX(created_at) FROM job_orders WHERE customer_id=c.id AND station_id=c.station_id),'2000-01-01'),
                COALESCE((SELECT MAX(COALESCE(transaction_date,created_at)) FROM fuel_transactions WHERE customer_id=c.id AND station_id=c.station_id),'2000-01-01')
            ),'2000-01-01') AS last_transaction
        FROM customers c
        WHERE $wc
        ORDER BY $registeredExpr DESC, c.id DESC
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats for registered-only customer module
    $statsWhere = [];
    $statsParams = [];
    customer_apply_station_scope($statsWhere, $statsParams, 'c', $role, $station_id);
    $statsWc = $statsWhere ? implode(' AND ', $statsWhere) : '1=1';
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN DATE($registeredExpr) = CURDATE() THEN 1 ELSE 0 END) as new_today,
            COUNT(*) as registered,
            SUM(CASE WHEN $statusExpr = 'active' THEN 1 ELSE 0 END) as active
        FROM customers c WHERE $statsWc
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'customers' => $customers, 'stats' => $stats]);
}

function fetchStaffCustomerTransactions(int $customerId, int $customerStation, string $moduleFilter = ''): array {
    global $pdo;

    $all = [];

    if ($moduleFilter === '' || $moduleFilter === 'Merchandise') {
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(transaction_date, created_at) AS txn_date,
                       COALESCE(transaction_id, CONCAT('MT-', id)) AS reference_no,
                       'Merchandise' AS module,
                       COALESCE(NULLIF(item_sku,''), NULLIF(job_order_service,''), 'Merchandise sale') AS description,
                       COALESCE(total_amount, 0) AS amount,
                       COALESCE(validation_status, workflow_status, 'Completed') AS status,
                       id AS source_id
                FROM merchandise_transactions
                WHERE customer_id = ? AND station_id = ?
            ");
            $stmt->execute([$customerId, $customerStation]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $all[] = $row;
            }
        } catch (Exception $e) {}
    }

    if ($moduleFilter === '' || $moduleFilter === 'Job Order') {
        try {
            $stmt = $pdo->prepare("
                SELECT created_at AS txn_date,
                       COALESCE(job_order_id, job_order_number, CONCAT('JO-', id)) AS reference_no,
                       'Job Order' AS module,
                       COALESCE(NULLIF(service_type,''), NULLIF(service_description,''), 'Service') AS description,
                       COALESCE(total_cost, estimated_cost, 0) AS amount,
                       COALESCE(status, validation_status, 'Completed') AS status,
                       id AS source_id
                FROM job_orders
                WHERE customer_id = ? AND station_id = ?
            ");
            $stmt->execute([$customerId, $customerStation]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $all[] = $row;
            }
        } catch (Exception $e) {}
    }

    if ($moduleFilter === '' || $moduleFilter === 'Fuel') {
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(transaction_date, created_at) AS txn_date,
                       COALESCE(transaction_id, CONCAT('FT-', id)) AS reference_no,
                       'Fuel' AS module,
                       CONCAT(COALESCE(fuel_type,'Fuel'), ' - ', COALESCE(liters_sold,0), 'L') AS description,
                       COALESCE(total_amount, 0) AS amount,
                       COALESCE(status, 'Completed') AS status,
                       id AS source_id
                FROM fuel_transactions
                WHERE customer_id = ? AND station_id = ?
            ");
            $stmt->execute([$customerId, $customerStation]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $all[] = $row;
            }
        } catch (Exception $e) {}
    }

    usort($all, fn($a, $b) => strtotime($b['txn_date'] ?? '1970-01-01') - strtotime($a['txn_date'] ?? '1970-01-01'));
    return $all;
}

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

    $where = ['c.id = ?'];
    $params = [$id];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    // ── STAFF PERMISSION: Only non-sensitive fields returned. ────────────────
    // gov_id_image, gov_id_type, cr_document, balance, current_balance,
    // credit_limit are deliberately excluded from this query.
    $stmt = $pdo->prepare("
        SELECT c.id,
            c.station_id,
            $customerIdExpr AS customer_id,
            $displayNameExpr AS display_name,
            $firstNameExpr AS first_name,
            $middleNameExpr AS middle_name,
            $lastNameExpr AS last_name,
            $contactExpr AS contact_number,
            c.address,
            $typeExpr AS customer_type,
            $statusExpr AS status,
            $registeredExpr AS registered_at
        FROM customers c
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) throw new Exception('Customer not found');

    $customerStation = (int)($customer['station_id'] ?? $station_id);
    $all = fetchStaffCustomerTransactions($id, $customerStation);

    $merch_count   = count(array_filter($all, fn($r) => $r['module']==='Merchandise'));
    $service_count = count(array_filter($all, fn($r) => $r['module']==='Job Order'));
    $fuel_count    = count(array_filter($all, fn($r) => $r['module']==='Fuel'));
    $total_amount  = array_sum(array_column($all, 'amount'));
    $last_tx       = !empty($all) ? $all[0]['txn_date'] : null;

    echo json_encode([
        'success'  => true,
        'customer' => $customer,
        'transactions' => [
            'merch_count'   => $merch_count,
            'service_count' => $service_count,
            'fuel_count'    => $fuel_count,
            'total_count'   => count($all),
            'total_amount'  => $total_amount,
            'last_transaction' => $last_tx
        ],
        'all_transactions' => $all
    ]);
}

function getTransactions() {
    global $pdo, $station_id, $role;
    $id     = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'No ID']); return; }

    $search = trim($_GET['search']     ?? '');
    $module = trim($_GET['module']     ?? '');
    $status = trim($_GET['tx_status']  ?? '');
    $dfrom  = trim($_GET['tx_from']    ?? '');
    $dto    = trim($_GET['tx_to']      ?? '');

    $where = ['c.id = ?'];
    $params = [$id];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);
    $stmt = $pdo->prepare("SELECT c.station_id FROM customers c WHERE " . implode(' AND ', $where));
    $stmt->execute($params);
    $customerStation = (int)$stmt->fetchColumn();
    if (!$customerStation) { echo json_encode(['success'=>false,'error'=>'Customer not found']); return; }

    $all = fetchStaffCustomerTransactions($id, $customerStation, $module);

    // Apply filters
    if ($search !== '') $all = array_filter($all, fn($r) => stripos($r['reference_no'],$search)!==false || stripos($r['description'],$search)!==false);
    if ($status !== '') $all = array_filter($all, fn($r) => strcasecmp($r['status'],$status)===0);
    if ($dfrom  !== '') $all = array_filter($all, fn($r) => substr($r['txn_date'],0,10) >= $dfrom);
    if ($dto    !== '') $all = array_filter($all, fn($r) => substr($r['txn_date'],0,10) <= $dto);

    echo json_encode(['success'=>true,'transactions'=>array_values($all)]);
}

function addCustomer() {
    global $pdo, $station_id, $me;

    if ($station_id <= 0) {
        throw new Exception('A station assignment is required before adding customers.');
    }

    $firstName  = trim($_POST['first_name']     ?? '');
    $middleName = trim($_POST['middle_name']     ?? '');
    $lastName   = trim($_POST['last_name']       ?? '');
    $contact    = trim($_POST['contact_number']  ?? '');
    $address    = trim($_POST['address']         ?? '');
    $type       = 'regular';
    $govIdType  = trim($_POST['gov_id_type']     ?? '') ?: null;

    if (!$firstName || !$lastName || !$contact || !$address)
        throw new Exception('All required fields must be filled');

    $customerId = generateCustomerId($station_id);
    $govIdImage = null; $crDocument = null;
    if (!empty($_FILES['gov_id_image']['name']))  $govIdImage = handleFileUpload($_FILES['gov_id_image'],'gov_id');
    if (!empty($_FILES['cr_document']['name']))   $crDocument = handleFileUpload($_FILES['cr_document'],'cr');

    $fullName = trim("$firstName $middleName $lastName");

    $newId = customer_insert_existing($pdo, [
        'customer_id'     => $customerId,
        'station_id'      => $station_id,
        'name'            => $fullName,
        'first_name'      => $firstName,
        'middle_name'     => $middleName,
        'last_name'       => $lastName,
        'contact_number'  => $contact,
        'phone'           => $contact,
        'address'         => $address,
        'customer_type'   => $type,
        'type'            => customer_legacy_billing_type($type),
        'gov_id_type'     => $govIdType,
        'id_type'         => $govIdType,
        'gov_id_image'    => $govIdImage,
        'cr_document'     => $crDocument,
        'status'          => 'active',
        'account_status'  => 'active',
        'registered_by'   => $me['id'] ?? null,
    ], [
        'registered_at'   => 'NOW()',
        'created_at'      => 'NOW()',
    ]);

    write_audit_log($pdo,'Create',"New customer: $fullName ($customerId)",'customers',$newId,'customer');
    echo json_encode(['success'=>true,'message'=>'Customer added successfully!','customer_id'=>$customerId,'id'=>$newId]);
}

function updateCustomer() {
    global $pdo, $station_id, $me;
    $id = (int)($_POST['customer_id'] ?? 0);
    if (!$id) throw new Exception('Customer ID required');

    $firstName  = trim($_POST['first_name']    ?? '');
    $middleName = trim($_POST['middle_name']   ?? '');
    $lastName   = trim($_POST['last_name']     ?? '');
    $contact    = trim($_POST['contact_number']?? '');
    $address    = trim($_POST['address']       ?? '');
    $type       = 'regular';

    if (!$firstName||!$lastName||!$contact||!$address) throw new Exception('All required fields must be filled');

    $check = $pdo->prepare("SELECT id FROM customers WHERE id=? AND station_id=?");
    $check->execute([$id,$station_id]);
    if (!$check->fetch()) throw new Exception('Customer not found');

    $fullName = trim("$firstName $middleName $lastName");
    customer_update_existing($pdo, [
        'name'           => $fullName,
        'first_name'     => $firstName,
        'middle_name'    => $middleName,
        'last_name'      => $lastName,
        'contact_number' => $contact,
        'phone'          => $contact,
        'address'        => $address,
        'customer_type'  => $type,
        'type'           => customer_legacy_billing_type($type),
        'updated_by'     => $me['id'] ?? null,
    ], 'id = ? AND station_id = ?', [$id, $station_id], [
        'updated_at'     => 'NOW()',
    ]);

    write_audit_log($pdo,'Update',"Updated customer: $firstName $lastName",'customers',$id,'customer');
    echo json_encode(['success'=>true,'message'=>'Customer updated successfully!']);
}

function generateCustomerId($stationId) {
    global $pdo;
    $prefix = "CUS-{$stationId}-" . date('Ym') . "-";
    $stmt   = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix.'%']);
    $last   = $stmt->fetchColumn();
    $num    = $last ? ((int)substr($last,-3))+1 : 1;
    return $prefix . str_pad($num,3,'0',STR_PAD_LEFT);
}

function handleFileUpload($file,$type) {
    $dir = __DIR__.'/../uploads/customer_documents/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $ext = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,['jpg','jpeg','png','pdf'])) throw new Exception('Invalid file type');
    if ($file['size']>5*1024*1024) throw new Exception('File too large (max 5MB)');
    $name = $type.'_'.time().'_'.bin2hex(random_bytes(6)).'.'.$ext;
    if (!move_uploaded_file($file['tmp_name'],$dir.$name)) throw new Exception('Upload failed');
    return 'uploads/customer_documents/'.$name;
}
?>
