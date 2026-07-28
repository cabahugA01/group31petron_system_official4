<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';

function manager_send_json(array $data): void {
    while (ob_get_level()) {
        @ob_end_clean();
    }
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data);
    exit;
}

$me = current_user();
$rawRole = $me['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'manager';
$role = role_key($rawRole);

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            manager_list_customers();
            break;
        case 'view':
            manager_view_customer();
            break;
        case 'add':
            manager_add_customer();
            break;
        case 'update':
            manager_update_customer();
            break;
        case 'archive':
            manager_archive_customer();
            break;
        case 'restore':
            manager_restore_customer();
            break;
        case 'deactivate':
            manager_deactivate_customer();
            break;
        case 'requests':
            manager_list_customer_requests();
            break;
        case 'approve_request':
            manager_approve_customer_request();
            break;
        case 'reject_request':
            manager_reject_customer_request();
            break;
        case 'archive_vehicle':
            manager_archive_vehicle();
            break;
        default:
            manager_send_json(['success' => false, 'error' => 'Invalid action.']);
    }
} catch (Throwable $e) {
    manager_send_json(['success' => false, 'error' => $e->getMessage()]);
}

function manager_has_table(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function manager_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $cache[$table] = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cache[$table][strtolower($row['Field'])] = $row['Field'];
        }
    } catch (Throwable $e) {}
    return $cache[$table];
}

function manager_has_col(PDO $pdo, string $table, string $column): bool {
    $cols = manager_table_columns($pdo, $table);
    return isset($cols[strtolower($column)]);
}

function manager_scope_customer_where(string $alias = 'c'): array {
    return [[], []];
}

function manager_customer_select_sql(): array {
    global $pdo;
    return [
        'customer_id'    => customer_id_expr($pdo, 'c'),
        'display_name'   => customer_display_name_expr($pdo, 'c'),
        'first_name'     => customer_first_name_expr($pdo, 'c'),
        'middle_name'    => customer_middle_name_expr($pdo, 'c'),
        'last_name'      => customer_last_name_expr($pdo, 'c'),
        'contact'        => customer_contact_expr($pdo, 'c'),
        'email'          => customer_expr_col($pdo, 'c', 'email', "''"),
        'type'           => customer_type_expr($pdo, 'c'),
        'status'         => customer_status_expr($pdo, 'c'),
        'registered_at'  => customer_registered_at_expr($pdo, 'c'),
        'balance'        => customer_balance_expr($pdo, 'c'),
        'credit_limit'   => customer_credit_limit_expr($pdo, 'c'),
        'credit_terms'   => customer_expr_col($pdo, 'c', 'credit_terms', "'30 Days'"),
        'vehicle_plate'  => customer_vehicle_expr($pdo, 'vehicle_plate', 'c'),
        'vehicle_make'   => customer_vehicle_expr($pdo, 'vehicle_make', 'c'),
        'vehicle_model'  => customer_vehicle_expr($pdo, 'vehicle_model', 'c'),
        'vehicle_type'   => customer_vehicle_expr($pdo, 'vehicle_type', 'c'),
        'address'        => customer_expr_col($pdo, 'c', 'address', "''"),
        'gov_id_type'    => customer_expr_col($pdo, 'c', 'gov_id_type', "''"),
        'gov_id_file'    => customer_expr_col($pdo, 'c', 'gov_id_file', "''"),
        'cr_file'        => customer_expr_col($pdo, 'c', 'cr_file', "''"),
        'or_file'        => customer_expr_col($pdo, 'c', 'or_file', "''"),
        'archived_at'    => customer_expr_col($pdo, 'c', 'archived_at', "NULL"),
        'archive_reason' => customer_expr_col($pdo, 'c', 'archive_reason', "''"),
        'archive_remarks' => customer_expr_col($pdo, 'c', 'archive_remarks', "''"),
    ];
}

function manager_log_timeline(int $customerId, string $eventType, string $description): void {
    global $pdo, $me;
    try {
        $stmt = $pdo->prepare("INSERT INTO customer_timeline (customer_id, event_type, description, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$customerId, $eventType, $description, $me['id'] ?? null]);
    } catch (Throwable $e) {}
}

function manager_customer_tx_summary(int $customerId, int $customerStation): array {
    global $pdo;

    $summary = [
        'total_transactions' => 0,
        'merchandise_purchases' => 0,
        'job_orders' => 0,
        'fuel_transactions' => 0,
        'total_amount' => 0.0,
        'last_transaction' => null,
    ];

    $tables = [
        'merchandise_transactions' => [
            'count_key' => 'merchandise_purchases',
            'date_cols' => ['transaction_date', 'created_at'],
            'amount_cols' => ['total_amount', 'grand_total', 'amount'],
            'customer_cols' => ['customer_id', 'credit_customer_id'],
        ],
        'job_orders' => [
            'count_key' => 'job_orders',
            'date_cols' => ['created_at', 'transaction_date'],
            'amount_cols' => ['total_cost', 'estimated_cost', 'amount'],
            'customer_cols' => ['customer_id'],
        ],
        'fuel_transactions' => [
            'count_key' => 'fuel_transactions',
            'date_cols' => ['transaction_date', 'created_at'],
            'amount_cols' => ['total_amount', 'amount'],
            'customer_cols' => ['customer_id'],
        ],
    ];

    foreach ($tables as $table => $meta) {
        if (!manager_has_table($pdo, $table)) continue;

        $customerConditions = [];
        foreach ($meta['customer_cols'] as $column) {
            if (manager_has_col($pdo, $table, $column)) {
                $customerConditions[] = "`$column` = :customer_id";
            }
        }
        if (!$customerConditions) continue;

        $dateCol = null;
        foreach ($meta['date_cols'] as $c) {
            if (manager_has_col($pdo, $table, $c)) { $dateCol = $c; break; }
        }
        $amountCol = null;
        foreach ($meta['amount_cols'] as $c) {
            if (manager_has_col($pdo, $table, $c)) { $amountCol = $c; break; }
        }

        $dateSql = $dateCol ? "MAX(`$dateCol`)" : "NULL";
        $amountSql = $amountCol ? "COALESCE(SUM(`$amountCol`),0)" : "0";

        try {
            $sql = "SELECT COUNT(*) AS row_count, $amountSql AS amount_total, $dateSql AS last_date
                    FROM `$table`
                    WHERE (" . implode(' OR ', $customerConditions) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':customer_id' => $customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $count = (int)($row['row_count'] ?? 0);
            $summary[$meta['count_key']] += $count;
            $summary['total_transactions'] += $count;
            $summary['total_amount'] += (float)($row['amount_total'] ?? 0);

            $last = $row['last_date'] ?? null;
            if ($last) {
                $summary['last_transaction'] = $summary['last_transaction'] ? max($summary['last_transaction'], $last) : $last;
            }
        } catch (Throwable $e) {}
    }

    return $summary;
}

function manager_empty_stats(): array {
    return [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'credit' => 0,
        'pending_requests' => 0,
        'new_this_month' => 0,
        'archived' => 0,
    ];
}

function manager_list_customers(): void {
    global $pdo;

    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
    } catch (Throwable $e) {
        manager_send_json(['success' => true, 'customers' => [], 'stats' => manager_empty_stats()]);
    }

    $search    = trim($_GET['search'] ?? '');
    $type      = trim($_GET['type'] ?? '');
    $status    = trim($_GET['status'] ?? '');
    $dateFrom  = trim($_GET['date_from'] ?? '');
    $dateTo    = trim($_GET['date_to'] ?? '');
    $tab       = trim($_GET['tab'] ?? 'list'); // list, archived, pending

    $validTypes = ['walk-in', 'regular', 'credit', 'fleet', 'corporate'];
    $validStatuses = ['active', 'inactive', 'archived'];

    $where = [];
    $params = [];
    $expr = manager_customer_select_sql();

    if ($tab === 'archived') {
        $where[] = "LOWER({$expr['status']}) = 'archived'";
    } else {
        if ($status !== '' && in_array($status, $validStatuses, true)) {
            $where[] = "LOWER({$expr['status']}) = ?";
            $params[] = strtolower($status);
        } else {
            $where[] = "LOWER({$expr['status']}) != 'archived'";
        }
    }

    if ($search !== '') {
        $where[] = "({$expr['customer_id']} LIKE ? OR {$expr['display_name']} LIKE ? OR {$expr['contact']} LIKE ? OR {$expr['vehicle_plate']} LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s);
    }

    if ($type !== '' && in_array($type, $validTypes, true)) {
        $where[] = "LOWER({$expr['type']}) = ?";
        $params[] = strtolower($type);
    }

    if ($dateFrom !== '') {
        $where[] = "DATE({$expr['registered_at']}) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE({$expr['registered_at']}) <= ?";
        $params[] = $dateTo;
    }

    $whereClause = $where ? implode(' AND ', $where) : '1=1';
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.station_id,
            {$expr['customer_id']} AS customer_id,
            {$expr['display_name']} AS customer_name,
            {$expr['first_name']} AS first_name,
            {$expr['middle_name']} AS middle_name,
            {$expr['last_name']} AS last_name,
            {$expr['contact']} AS contact_number,
            {$expr['email']} AS email,
            {$expr['address']} AS address,
            {$expr['type']} AS customer_type,
            {$expr['status']} AS status,
            {$expr['vehicle_plate']} AS plate_no,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['credit_terms']} AS credit_terms,
            {$expr['balance']} AS outstanding_balance,
            {$expr['gov_id_type']} AS gov_id_type,
            {$expr['gov_id_file']} AS gov_id_file,
            {$expr['cr_file']} AS cr_file,
            {$expr['or_file']} AS or_file,
            {$expr['archived_at']} AS archived_at,
            {$expr['archive_reason']} AS archive_reason,
            {$expr['archive_remarks']} AS archive_remarks,
            {$expr['registered_at']} AS registered_at
        FROM customers c
        WHERE $whereClause
        ORDER BY c.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $custIds = array_column($customers, 'id');
    $vehiclesMap = [];
    if ($custIds) {
        try {
            $inClause = implode(',', array_fill(0, count($custIds), '?'));
            $vStmt = $pdo->prepare("SELECT customer_id, plate_number, vehicle_type, brand, model FROM customer_vehicles WHERE customer_id IN ($inClause) AND status = 'active'");
            $vStmt->execute($custIds);
            while ($vRow = $vStmt->fetch(PDO::FETCH_ASSOC)) {
                $vehiclesMap[(int)$vRow['customer_id']][] = $vRow;
            }
        } catch (Throwable $e) {}
    }

    foreach ($customers as &$customer) {
        $cId = (int)$customer['id'];
        $cVehicles = $vehiclesMap[$cId] ?? [];
        if (!$cVehicles && !empty($customer['plate_no'])) {
            $cVehicles[] = [
                'plate_number' => $customer['plate_no'],
                'vehicle_type' => $customer['vehicle_type'] ?? '',
                'brand'        => $customer['vehicle_make'] ?? '',
                'model'        => $customer['vehicle_model'] ?? '',
            ];
        }
        $customer['vehicles'] = $cVehicles;
        $customer['available_credit'] = max(0, (float)$customer['credit_limit'] - (float)($customer['outstanding_balance'] ?? 0));
        $customer['vehicle_count'] = count($cVehicles);
        $customer['last_transaction'] = null;
    }
    unset($customer);

    $firstDayMonth = date('Y-m-01');

    $statsStmt = $pdo->query("
        SELECT
            COUNT(CASE WHEN {$expr['status']} != 'archived' THEN 1 END) AS total,
            SUM(CASE WHEN {$expr['status']} = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN {$expr['status']} = 'inactive' THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN {$expr['status']} = 'archived' THEN 1 ELSE 0 END) AS archived,
            SUM(CASE WHEN {$expr['type']} = 'credit' AND {$expr['status']} != 'archived' THEN 1 ELSE 0 END) AS credit,
            SUM(CASE WHEN DATE({$expr['registered_at']}) >= '$firstDayMonth' AND {$expr['status']} != 'archived' THEN 1 ELSE 0 END) AS new_this_month
        FROM customers c
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: manager_empty_stats();
    $stats['pending_requests'] = manager_count_pending_requests();

    manager_send_json(['success' => true, 'customers' => $customers, 'stats' => $stats]);
}

function manager_view_customer(): void {
    global $pdo;

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $expr = manager_customer_select_sql();
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.station_id,
            {$expr['customer_id']} AS customer_id,
            {$expr['display_name']} AS customer_name,
            {$expr['first_name']} AS first_name,
            {$expr['middle_name']} AS middle_name,
            {$expr['last_name']} AS last_name,
            {$expr['contact']} AS contact_number,
            {$expr['email']} AS email,
            {$expr['address']} AS address,
            {$expr['type']} AS customer_type,
            {$expr['status']} AS status,
            {$expr['vehicle_plate']} AS plate_no,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['credit_terms']} AS credit_terms,
            {$expr['balance']} AS outstanding_balance,
            {$expr['gov_id_type']} AS gov_id_type,
            {$expr['gov_id_file']} AS gov_id_file,
            {$expr['cr_file']} AS cr_file,
            {$expr['or_file']} AS or_file,
            {$expr['archived_at']} AS archived_at,
            {$expr['archive_reason']} AS archive_reason,
            {$expr['archive_remarks']} AS archive_remarks,
            {$expr['registered_at']} AS registered_at
        FROM customers c
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new Exception('Customer not found.');
    }

    $customerStation = (int)($customer['station_id'] ?? 0);
    $summary = manager_customer_tx_summary($id, $customerStation);
    $customer['available_credit'] = max(0, (float)$customer['credit_limit'] - (float)$customer['outstanding_balance']);

    $vehicles = [];
    try {
        $vStmt = $pdo->prepare("SELECT * FROM customer_vehicles WHERE customer_id = ? ORDER BY id ASC");
        $vStmt->execute([$id]);
        $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if (empty($vehicles) && !empty($customer['plate_no'])) {
        $vehicles[] = [
            'id' => 0,
            'plate_number' => $customer['plate_no'],
            'vehicle_type' => $customer['vehicle_type'] ?: 'N/A',
            'brand'        => $customer['vehicle_make'] ?: 'N/A',
            'model'        => $customer['vehicle_model'] ?: 'N/A',
            'status'       => 'active'
        ];
    }

    $transactions = [];
    if (manager_has_table($pdo, 'merchandise_transactions')) {
        try {
            $tStmt = $pdo->prepare("SELECT transaction_id, created_at AS date, 'Merchandise' AS type, total_amount AS amount, validation_status AS status FROM merchandise_transactions WHERE customer_id = ? OR credit_customer_id = ? ORDER BY created_at DESC LIMIT 10");
            $tStmt->execute([$id, $id]);
            $transactions = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    $jobOrders = [];
    if (manager_has_table($pdo, 'job_orders')) {
        try {
            $jStmt = $pdo->prepare("SELECT job_order_number AS jo_no, vehicle_plate AS vehicle, service_type AS service, assigned_mechanic_id AS mechanic, status FROM job_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10");
            $jStmt->execute([$id]);
            $jobOrders = $jStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    $timeline = [];
    try {
        $tmStmt = $pdo->prepare("SELECT event_type, description, created_at FROM customer_timeline WHERE customer_id = ? ORDER BY created_at ASC");
        $tmStmt->execute([$id]);
        $timeline = $tmStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if (empty($timeline)) {
        $timeline[] = [
            'event_type' => 'Customer Created',
            'description' => 'Customer account registered in system.',
            'created_at' => $customer['registered_at'] ?: date('Y-m-d H:i:s')
        ];
        if ((float)$customer['credit_limit'] > 0) {
            $timeline[] = [
                'event_type' => 'Credit Approved',
                'description' => 'Credit limit approved: ₱' . number_format($customer['credit_limit'], 2),
                'created_at' => $customer['registered_at'] ?: date('Y-m-d H:i:s')
            ];
        }
        if ($summary['last_transaction']) {
            $timeline[] = [
                'event_type' => 'Last Transaction',
                'description' => 'Most recent purchase or job order transaction processed.',
                'created_at' => $summary['last_transaction']
            ];
        }
        if ($customer['status'] === 'archived') {
            $timeline[] = [
                'event_type' => 'Archived',
                'description' => 'Account archived. Reason: ' . ($customer['archive_reason'] ?: 'Inactive'),
                'created_at' => $customer['archived_at'] ?: date('Y-m-d H:i:s')
            ];
        }
    }

    manager_send_json([
        'success' => true,
        'customer' => $customer,
        'summary' => $summary,
        'vehicles' => $vehicles,
        'transactions' => $transactions,
        'job_orders' => $jobOrders,
        'timeline' => $timeline,
    ]);
}

function manager_handle_file_upload(string $fileKey): ?string {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmpName = $_FILES[$fileKey]['tmp_name'];
    $name = $_FILES[$fileKey]['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Invalid file type for {$fileKey}. Allowed: JPG, PNG, PDF, DOC.");
    }

    $uploadDir = __DIR__ . '/../uploads/customer_docs/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $newName = uniqid('doc_', true) . '.' . $ext;
    $destination = $uploadDir . $newName;

    if (move_uploaded_file($tmpName, $destination)) {
        return 'uploads/customer_docs/' . $newName;
    }
    return null;
}

function manager_validate_customer_payload(): array {
    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = trim($_POST['middle_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $contact     = trim($_POST['contact_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $type        = trim($_POST['customer_type'] ?? 'walk-in');
    $status      = trim($_POST['status'] ?? 'active');
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);
    $creditTerms = trim($_POST['credit_terms'] ?? '30 Days');
    $govIdType   = trim($_POST['gov_id_type'] ?? '');

    if ($firstName === '') {
        throw new Exception('Customer First Name is required.');
    }
    if ($lastName === '') {
        $lastName = $firstName;
    }
    if ($contact === '') {
        $contact = 'N/A';
    }

    if (!in_array($type, ['walk-in', 'regular', 'credit', 'fleet', 'corporate'], true)) {
        $type = 'walk-in';
    }
    if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
        $status = 'active';
    }
    if ($type !== 'credit') {
        $creditLimit = 0;
    }

    $govIdFile = manager_handle_file_upload('gov_id_file');
    $crFile    = manager_handle_file_upload('cr_file');
    $orFile    = manager_handle_file_upload('or_file');

    return [
        'first_name'     => $firstName,
        'middle_name'    => $middleName,
        'last_name'      => $lastName,
        'contact_number' => $contact,
        'email'          => $email,
        'address'        => $address,
        'customer_type'  => $type,
        'status'         => $status,
        'credit_limit'   => max(0, $creditLimit),
        'credit_terms'   => $creditTerms,
        'gov_id_type'    => $govIdType,
        'gov_id_file'    => $govIdFile,
        'cr_file'        => $crFile,
        'or_file'        => $orFile,
        'vehicle_plate'  => strtoupper(trim($_POST['plate_no'] ?? $_POST['vehicle_plate'] ?? '')),
        'vehicle_make'   => trim($_POST['vehicle_make'] ?? $_POST['brand'] ?? ''),
        'vehicle_brand'  => trim($_POST['vehicle_make'] ?? $_POST['brand'] ?? ''),
        'vehicle_model'  => trim($_POST['vehicle_model'] ?? ''),
        'vehicle_type'   => trim($_POST['vehicle_type'] ?? ''),
    ];
}

function manager_generate_customer_id(int $stationId): string {
    global $pdo;
    $prefix = "CUS-{$stationId}-" . date('Ym') . "-";
    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)$stmt->fetchColumn();
    $next = $last ? ((int)substr($last, -3) + 1) : 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function manager_audit(string $action, string $details, int $entityId = 0): void {
    global $pdo;
    if (function_exists('write_audit_log')) {
        try {
            write_audit_log($pdo, $action, $details, 'customers', $entityId, 'customer');
        } catch (Throwable $e) {}
    }
}

function manager_add_customer(): void {
    global $pdo, $station_id, $me;

    $stId = $station_id;
    if ($stId <= 0 && !empty($me['id'])) {
        try {
            $uStmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
            $uStmt->execute([(int)$me['id']]);
            $stId = (int)$uStmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($stId <= 0) {
        try {
            $sStmt = $pdo->query("SELECT id FROM stations ORDER BY id ASC LIMIT 1");
            $stId = (int)$sStmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($stId <= 0) {
        $stId = 1253;
    }

    $data = manager_validate_customer_payload();
    $customerId = manager_generate_customer_id($stId);
    $fullName = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']);

    $insertValues = [
        'customer_id'         => $customerId,
        'station_id'          => $stId,
        'name'                => $fullName,
        'first_name'          => $data['first_name'],
        'middle_name'         => $data['middle_name'],
        'last_name'           => $data['last_name'],
        'contact_number'      => $data['contact_number'],
        'phone'               => $data['contact_number'],
        'email'               => $data['email'],
        'address'             => $data['address'],
        'customer_type'       => $data['customer_type'],
        'type'                => customer_legacy_billing_type($data['customer_type']),
        'status'              => $data['status'],
        'account_status'      => $data['status'],
        'vehicle_plate'       => $data['vehicle_plate'],
        'plate_number'        => $data['vehicle_plate'],
        'vehicle_make'        => $data['vehicle_make'],
        'vehicle_brand'       => $data['vehicle_make'],
        'vehicle_model'       => $data['vehicle_model'],
        'vehicle_type'        => $data['vehicle_type'],
        'credit_limit'        => $data['credit_limit'],
        'credit_terms'        => $data['credit_terms'],
        'gov_id_type'         => $data['gov_id_type'],
        'outstanding_balance' => 0,
        'current_balance'     => 0,
        'balance'             => 0,
        'registered_by'       => $me['id'] ?? null,
    ];

    if ($data['gov_id_file']) $insertValues['gov_id_file'] = $data['gov_id_file'];
    if ($data['cr_file'])     $insertValues['cr_file']     = $data['cr_file'];
    if ($data['or_file'])     $insertValues['or_file']     = $data['or_file'];

    $newId = customer_insert_existing($pdo, $insertValues, [
        'registered_at' => 'NOW()',
        'created_at'    => 'NOW()',
    ]);

    if (isset($_POST['vehicles_json'])) {
        $vList = json_decode($_POST['vehicles_json'], true) ?: [];
        foreach ($vList as $v) {
            $plate = strtoupper(trim($v['plate_number'] ?? ''));
            if ($plate !== '') {
                try {
                    $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, year_model, color, engine_no, chassis_no, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                    $vStmt->execute([
                        $newId,
                        $plate,
                        trim($v['vehicle_type'] ?? ''),
                        trim($v['brand'] ?? ''),
                        trim($v['model'] ?? ''),
                        trim($v['year_model'] ?? ''),
                        trim($v['color'] ?? ''),
                        trim($v['engine_no'] ?? ''),
                        trim($v['chassis_no'] ?? '')
                    ]);
                    manager_log_timeline($newId, 'Vehicle Added', "Vehicle {$plate} ({$v['brand']} {$v['model']}) registered.");
                } catch (Throwable $e) {}
            }
        }
    } else if (!empty($data['vehicle_plate'])) {
        try {
            $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
            $vStmt->execute([$newId, $data['vehicle_plate'], $data['vehicle_type'], $data['vehicle_make'], $data['vehicle_model']]);
        } catch (Throwable $e) {}
    }

    manager_log_timeline($newId, 'Customer Created', "Customer {$fullName} registered into system.");
    if ($data['customer_type'] === 'credit' && $data['credit_limit'] > 0) {
        manager_log_timeline($newId, 'Credit Approved', "Credit limit approved: ₱" . number_format($data['credit_limit'], 2));
    }

    manager_audit('Create', "Created customer {$fullName} ({$customerId})", $newId);
    manager_send_json(['success' => true, 'message' => 'Customer has been saved successfully.', 'id' => $newId, 'customer_id' => $customerId]);
}

function manager_update_customer(): void {
    global $pdo, $station_id, $role, $me;

    $id = (int)($_POST['id'] ?? $_POST['customer_id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $data = manager_validate_customer_payload();
    $fullName = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']);

    $updateValues = [
        'name'           => $fullName,
        'first_name'     => $data['first_name'],
        'middle_name'    => $data['middle_name'],
        'last_name'      => $data['last_name'],
        'contact_number' => $data['contact_number'],
        'phone'          => $data['contact_number'],
        'email'          => $data['email'],
        'address'        => $data['address'],
        'customer_type'  => $data['customer_type'],
        'type'           => customer_legacy_billing_type($data['customer_type']),
        'status'         => $data['status'],
        'account_status' => $data['status'],
        'vehicle_plate'  => $data['vehicle_plate'],
        'plate_number'   => $data['vehicle_plate'],
        'vehicle_make'   => $data['vehicle_make'],
        'vehicle_brand'  => $data['vehicle_make'],
        'vehicle_model'  => $data['vehicle_model'],
        'vehicle_type'   => $data['vehicle_type'],
        'credit_limit'   => $data['credit_limit'],
        'credit_terms'   => $data['credit_terms'],
        'gov_id_type'    => $data['gov_id_type'],
        'updated_by'     => $me['id'] ?? null,
    ];

    if ($data['gov_id_file']) $updateValues['gov_id_file'] = $data['gov_id_file'];
    if ($data['cr_file'])     $updateValues['cr_file']     = $data['cr_file'];
    if ($data['or_file'])     $updateValues['or_file']     = $data['or_file'];

    customer_update_existing($pdo, $updateValues, 'id = ?', [$id], [
        'updated_at' => 'NOW()',
    ]);

    if (isset($_POST['vehicles_json'])) {
        $vList = json_decode($_POST['vehicles_json'], true) ?: [];
        foreach ($vList as $v) {
            $plate = strtoupper(trim($v['plate_number'] ?? ''));
            if ($plate !== '') {
                try {
                    $vCheck = $pdo->prepare("SELECT id FROM customer_vehicles WHERE customer_id = ? AND plate_number = ? LIMIT 1");
                    $vCheck->execute([$id, $plate]);
                    if (!$vCheck->fetchColumn()) {
                        $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, year_model, color, engine_no, chassis_no, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                        $vStmt->execute([
                            $id,
                            $plate,
                            trim($v['vehicle_type'] ?? ''),
                            trim($v['brand'] ?? ''),
                            trim($v['model'] ?? ''),
                            trim($v['year_model'] ?? ''),
                            trim($v['color'] ?? ''),
                            trim($v['engine_no'] ?? ''),
                            trim($v['chassis_no'] ?? '')
                        ]);
                        manager_log_timeline($id, 'Vehicle Added', "Vehicle {$plate} added.");
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    manager_log_timeline($id, 'Customer Updated', "Customer details updated by Manager.");
    manager_audit('Update', "Updated customer {$fullName}", $id);
    manager_send_json(['success' => true, 'message' => 'Customer has been updated successfully.']);
}

function manager_archive_customer(): void {
    global $pdo, $me;

    $id      = (int)($_POST['id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? 'Others');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $updated = customer_update_existing($pdo, [
        'status'          => 'archived',
        'account_status'  => 'archived',
        'archive_reason'  => $reason,
        'archive_remarks' => $remarks,
        'archived_by'     => $me['id'] ?? null,
        'updated_by'      => $me['id'] ?? null,
    ], 'id = ?', [$id], [
        'archived_at' => 'NOW()',
        'updated_at'  => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already archived.');
    }

    manager_log_timeline($id, 'Archived', "Customer archived. Reason: {$reason}. Remarks: {$remarks}");
    manager_audit('Archive', "Archived customer ID {$id}. Reason: {$reason}", $id);
    manager_send_json(['success' => true, 'message' => 'Customer has been archived successfully.']);
}

function manager_restore_customer(): void {
    global $pdo, $me;

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $updated = customer_update_existing($pdo, [
        'status'          => 'active',
        'account_status'  => 'active',
        'archive_reason'  => null,
        'archive_remarks' => null,
        'archived_at'     => null,
        'archived_by'     => null,
        'updated_by'      => $me['id'] ?? null,
    ], 'id = ?', [$id], [
        'updated_at' => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already active.');
    }

    manager_log_timeline($id, 'Restored', "Customer account restored to Active status.");
    manager_audit('Restore', "Restored customer ID {$id} to Active status.", $id);
    manager_send_json(['success' => true, 'message' => 'Customer account restored successfully.']);
}

function manager_deactivate_customer(): void {
    global $pdo, $me;

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $updated = customer_update_existing($pdo, [
        'status' => 'inactive',
        'account_status' => 'inactive',
        'updated_by' => $me['id'] ?? null,
    ], 'id = ?', [$id], [
        'updated_at' => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already inactive.');
    }

    manager_log_timeline($id, 'Deactivated', "Customer set to Inactive status.");
    manager_audit('Deactivate', "Deactivated customer ID {$id}", $id);
    manager_send_json(['success' => true, 'message' => 'Customer has been deactivated.']);
}

function manager_archive_vehicle(): void {
    global $pdo;
    $vId = (int)($_POST['vehicle_id'] ?? 0);
    if ($vId <= 0) {
        throw new Exception('Vehicle ID required.');
    }
    $stmt = $pdo->prepare("UPDATE customer_vehicles SET status = 'archived' WHERE id = ?");
    $stmt->execute([$vId]);
    manager_send_json(['success' => true, 'message' => 'Vehicle archived successfully.']);
}

function manager_count_pending_requests(): int {
    global $pdo;
    if (!manager_has_table($pdo, 'customer_requests')) {
        return 0;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM customer_requests WHERE LOWER(status) = 'pending'");
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function manager_list_customer_requests(): void {
    global $pdo;

    if (!manager_has_table($pdo, 'customer_requests')) {
        manager_send_json(['success' => true, 'requests' => []]);
    }

    $stmt = $pdo->query("
        SELECT
            cr.*,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS requested_by_name
        FROM customer_requests cr
        LEFT JOIN users u ON u.id = cr.requested_by
        WHERE LOWER(cr.status) = 'pending'
        ORDER BY cr.created_at DESC, cr.id DESC
        LIMIT 100
    ");
    manager_send_json(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function manager_fetch_request_for_review(int $requestId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM customer_requests WHERE id = ? AND LOWER(status) = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new Exception('Customer request not found.');
    }
    return $request;
}

function manager_approve_customer_request(): void {
    global $pdo, $me, $station_id;

    $requestId = (int)($_POST['id'] ?? 0);
    if ($requestId <= 0) {
        throw new Exception('Request ID is required.');
    }

    $request = manager_fetch_request_for_review($requestId);
    $customerStation = (int)($request['station_id'] ?? $station_id ?: 1253);

    $pdo->beginTransaction();
    try {
        $customerId = manager_generate_customer_id($customerStation);
        $type = in_array($request['customer_type'], ['walk-in', 'regular', 'credit', 'fleet', 'corporate'], true)
            ? $request['customer_type']
            : 'walk-in';
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ?? '') . ' ' . $request['last_name']);

        $newId = customer_insert_existing($pdo, [
            'customer_id'         => $customerId,
            'station_id'          => $customerStation,
            'name'                => $fullName,
            'first_name'          => $request['first_name'],
            'middle_name'         => $request['middle_name'],
            'last_name'           => $request['last_name'],
            'contact_number'      => $request['contact_number'],
            'phone'               => $request['contact_number'],
            'address'             => $request['address'],
            'customer_type'       => $type,
            'type'                => customer_legacy_billing_type($type),
            'status'              => 'active',
            'account_status'      => 'active',
            'vehicle_plate'       => strtoupper($request['vehicle_plate'] ?? ''),
            'plate_number'        => strtoupper($request['vehicle_plate'] ?? ''),
            'vehicle_make'        => $request['vehicle_make'] ?? '',
            'vehicle_brand'       => $request['vehicle_make'] ?? '',
            'vehicle_model'       => $request['vehicle_model'] ?? '',
            'vehicle_type'        => $request['vehicle_type'] ?? '',
            'credit_limit'        => 0,
            'outstanding_balance' => 0,
            'current_balance'     => 0,
            'balance'             => 0,
            'registered_by'       => $me['id'] ?? null,
        ], [
            'registered_at' => 'NOW()',
            'created_at'    => 'NOW()',
        ]);

        if (!empty($request['vehicle_plate'])) {
            try {
                $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                $vStmt->execute([$newId, strtoupper($request['vehicle_plate']), $request['vehicle_type'] ?? '', $request['vehicle_make'] ?? '', $request['vehicle_model'] ?? '']);
            } catch (Throwable $e) {}
        }

        manager_log_timeline($newId, 'Customer Created', "Approved from Staff request #{$requestId}.");

        $stmt = $pdo->prepare("
            UPDATE customer_requests
            SET status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                customer_record_id = ?,
                manager_remarks = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$me['id'] ?? null, $newId, trim($_POST['remarks'] ?? ''), $requestId]);
        $pdo->commit();

        manager_audit('Approve', "Approved customer request #{$requestId} as {$customerId}", $newId);
        manager_send_json(['success' => true, 'message' => 'Customer request approved and customer record created.', 'customer_id' => $customerId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function manager_reject_customer_request(): void {
    global $pdo, $me;

    $requestId = (int)($_POST['id'] ?? 0);
    if ($requestId <= 0) {
        throw new Exception('Request ID is required.');
    }
    manager_fetch_request_for_review($requestId);

    $stmt = $pdo->prepare("
        UPDATE customer_requests
        SET status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW(),
            manager_remarks = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$me['id'] ?? null, trim($_POST['remarks'] ?? ''), $requestId]);

    manager_audit('Reject', "Rejected customer request #{$requestId}", $requestId);
    manager_send_json(['success' => true, 'message' => 'Customer request rejected.']);
}
