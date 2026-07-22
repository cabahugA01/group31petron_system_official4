<?php
ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

if (!in_array($role, ['manager', 'superadmin', 'developer'], true)) {
    echo json_encode(['success' => false, 'error' => 'Only managers can manage customers.']);
    exit;
}

if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Your account is not assigned to a station.']);
    exit;
}

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
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

function manager_date_col(PDO $pdo, string $table, array $choices): ?string {
    foreach ($choices as $column) {
        if (manager_has_col($pdo, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function manager_amount_col(PDO $pdo, string $table, array $choices): ?string {
    foreach ($choices as $column) {
        if (manager_has_col($pdo, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function manager_scope_customer_where(string $alias = 'c'): array {
    global $role, $station_id;
    $where = [];
    $params = [];
    customer_apply_station_scope($where, $params, $alias, $role, $station_id);
    return [$where, $params];
}

function manager_customer_select_sql(): array {
    global $pdo;
    return [
        'customer_id' => customer_id_expr($pdo, 'c'),
        'display_name' => customer_display_name_expr($pdo, 'c'),
        'first_name' => customer_first_name_expr($pdo, 'c'),
        'middle_name' => customer_middle_name_expr($pdo, 'c'),
        'last_name' => customer_last_name_expr($pdo, 'c'),
        'contact' => customer_contact_expr($pdo, 'c'),
        'type' => customer_type_expr($pdo, 'c'),
        'status' => customer_status_expr($pdo, 'c'),
        'registered_at' => customer_registered_at_expr($pdo, 'c'),
        'balance' => customer_balance_expr($pdo, 'c'),
        'credit_limit' => customer_credit_limit_expr($pdo, 'c'),
        'vehicle_plate' => customer_vehicle_expr($pdo, 'vehicle_plate', 'c'),
        'vehicle_make' => customer_vehicle_expr($pdo, 'vehicle_make', 'c'),
        'vehicle_model' => customer_vehicle_expr($pdo, 'vehicle_model', 'c'),
        'vehicle_type' => customer_vehicle_expr($pdo, 'vehicle_type', 'c'),
        'address' => customer_expr_col($pdo, 'c', 'address', "''"),
    ];
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
        if (!manager_has_table($pdo, $table)) {
            continue;
        }

        $customerConditions = [];
        foreach ($meta['customer_cols'] as $column) {
            if (manager_has_col($pdo, $table, $column)) {
                $customerConditions[] = "`$column` = :customer_id";
            }
        }
        if (!$customerConditions || !manager_has_col($pdo, $table, 'station_id')) {
            continue;
        }

        $dateCol = manager_date_col($pdo, $table, $meta['date_cols']);
        $amountCol = manager_amount_col($pdo, $table, $meta['amount_cols']);
        $dateSql = $dateCol ? "MAX(`$dateCol`)" : "NULL";
        $amountSql = $amountCol ? "COALESCE(SUM(`$amountCol`),0)" : "0";

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS row_count, $amountSql AS amount_total, $dateSql AS last_date
                FROM `$table`
                WHERE station_id = :station_id
                  AND (" . implode(' OR ', $customerConditions) . ")
            ");
            $stmt->execute([
                ':station_id' => $customerStation,
                ':customer_id' => $customerId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $count = (int)($row['row_count'] ?? 0);
            $summary[$meta['count_key']] += $count;
            $summary['total_transactions'] += $count;
            $summary['total_amount'] += (float)($row['amount_total'] ?? 0);

            $last = $row['last_date'] ?? null;
            if ($last) {
                $summary['last_transaction'] = $summary['last_transaction']
                    ? max($summary['last_transaction'], $last)
                    : $last;
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
    ];
}

function manager_list_customers(): void {
    global $pdo, $role, $station_id;

    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
    } catch (Throwable $e) {
        echo json_encode(['success' => true, 'customers' => [], 'stats' => manager_empty_stats()]);
        return;
    }

    $search = trim($_GET['search'] ?? '');
    $type = trim($_GET['type'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $validTypes = ['walk-in', 'regular', 'credit'];
    $validStatuses = ['active', 'inactive'];

    [$where, $params] = manager_scope_customer_where('c');
    $expr = manager_customer_select_sql();

    if ($search !== '') {
        $where[] = "({$expr['customer_id']} LIKE ? OR {$expr['display_name']} LIKE ? OR {$expr['contact']} LIKE ? OR {$expr['vehicle_plate']} LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s);
    }
    if (in_array($type, $validTypes, true)) {
        $where[] = "{$expr['type']} = ?";
        $params[] = $type;
    }
    if (in_array($status, $validStatuses, true)) {
        $where[] = "{$expr['status']} = ?";
        $params[] = $status;
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
            {$expr['address']} AS address,
            {$expr['type']} AS customer_type,
            {$expr['status']} AS status,
            {$expr['vehicle_plate']} AS plate_no,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['balance']} AS outstanding_balance,
            {$expr['registered_at']} AS registered_at
        FROM customers c
        WHERE $whereClause
        ORDER BY {$expr['registered_at']} DESC, c.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($customers as &$customer) {
        $customerStation = customer_can_view_all_stations($role) ? (int)$customer['station_id'] : $station_id;
        $tx = manager_customer_tx_summary((int)$customer['id'], $customerStation);
        $customer = array_merge($customer, $tx);
        $customer['available_credit'] = max(0, (float)$customer['credit_limit'] - (float)$customer['outstanding_balance']);
    }
    unset($customer);

    $statsWhere = [];
    $statsParams = [];
    customer_apply_station_scope($statsWhere, $statsParams, 'c', $role, $station_id);
    $statsClause = $statsWhere ? implode(' AND ', $statsWhere) : '1=1';
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN {$expr['status']} = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN {$expr['status']} = 'inactive' THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN {$expr['type']} = 'credit' THEN 1 ELSE 0 END) AS credit
        FROM customers c
        WHERE $statsClause
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: manager_empty_stats();
    $stats['pending_requests'] = manager_count_pending_requests();

    echo json_encode(['success' => true, 'customers' => $customers, 'stats' => $stats]);
}

function manager_view_customer(): void {
    global $pdo, $role, $station_id;

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    [$where, $params] = manager_scope_customer_where('c');
    array_unshift($where, 'c.id = ?');
    array_unshift($params, $id);

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
            {$expr['address']} AS address,
            {$expr['type']} AS customer_type,
            {$expr['status']} AS status,
            {$expr['vehicle_plate']} AS plate_no,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['balance']} AS outstanding_balance,
            {$expr['registered_at']} AS registered_at
        FROM customers c
        WHERE " . implode(' AND ', $where) . "
        LIMIT 1
    ");
    $stmt->execute($params);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new Exception('Customer not found.');
    }

    $customerStation = customer_can_view_all_stations($role) ? (int)$customer['station_id'] : $station_id;
    $summary = manager_customer_tx_summary($id, $customerStation);
    $customer['available_credit'] = max(0, (float)$customer['credit_limit'] - (float)$customer['outstanding_balance']);

    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'summary' => $summary,
    ]);
}

function manager_validate_customer_payload(): array {
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $type = trim($_POST['customer_type'] ?? 'walk-in');
    $status = trim($_POST['status'] ?? 'active');
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);

    if ($firstName === '' || $lastName === '' || $contact === '') {
        throw new Exception('First name, last name, and contact number are required.');
    }
    if (!in_array($type, ['walk-in', 'regular', 'credit'], true)) {
        throw new Exception('Invalid customer type.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new Exception('Invalid customer status.');
    }
    if ($type !== 'credit') {
        $creditLimit = 0;
    }

    return [
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'contact_number' => $contact,
        'address' => $address,
        'customer_type' => $type,
        'status' => $status,
        'vehicle_plate' => strtoupper(trim($_POST['plate_no'] ?? $_POST['vehicle_plate'] ?? '')),
        'vehicle_make' => trim($_POST['vehicle_make'] ?? ''),
        'vehicle_brand' => trim($_POST['vehicle_make'] ?? ''),
        'vehicle_model' => trim($_POST['vehicle_model'] ?? ''),
        'vehicle_type' => trim($_POST['vehicle_type'] ?? ''),
        'credit_limit' => max(0, $creditLimit),
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

    if ($station_id <= 0) {
        throw new Exception('A station assignment is required before adding customers.');
    }

    $data = manager_validate_customer_payload();
    $customerId = manager_generate_customer_id($station_id);
    $fullName = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']);

    $newId = customer_insert_existing($pdo, [
        'customer_id' => $customerId,
        'station_id' => $station_id,
        'name' => $fullName,
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'],
        'last_name' => $data['last_name'],
        'contact_number' => $data['contact_number'],
        'phone' => $data['contact_number'],
        'address' => $data['address'],
        'customer_type' => $data['customer_type'],
        'type' => customer_legacy_billing_type($data['customer_type']),
        'status' => $data['status'],
        'account_status' => $data['status'],
        'vehicle_plate' => $data['vehicle_plate'],
        'plate_number' => $data['vehicle_plate'],
        'vehicle_make' => $data['vehicle_make'],
        'vehicle_brand' => $data['vehicle_make'],
        'vehicle_model' => $data['vehicle_model'],
        'vehicle_type' => $data['vehicle_type'],
        'credit_limit' => $data['credit_limit'],
        'outstanding_balance' => 0,
        'current_balance' => 0,
        'balance' => 0,
        'registered_by' => $me['id'] ?? null,
    ], [
        'registered_at' => 'NOW()',
        'created_at' => 'NOW()',
    ]);

    manager_audit('Create', "Created customer {$fullName} ({$customerId})", $newId);
    echo json_encode(['success' => true, 'message' => 'Customer has been saved successfully.', 'id' => $newId, 'customer_id' => $customerId]);
}

function manager_update_customer(): void {
    global $pdo, $station_id, $role, $me;

    $id = (int)($_POST['id'] ?? $_POST['customer_id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    [$where, $params] = manager_scope_customer_where('c');
    array_unshift($where, 'c.id = ?');
    array_unshift($params, $id);
    $check = $pdo->prepare("SELECT c.id FROM customers c WHERE " . implode(' AND ', $where) . " LIMIT 1");
    $check->execute($params);
    if (!$check->fetchColumn()) {
        throw new Exception('Customer not found.');
    }

    $data = manager_validate_customer_payload();
    $fullName = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']);
    $whereSql = customer_can_view_all_stations($role) ? 'id = ?' : 'id = ? AND station_id = ?';
    $whereValues = customer_can_view_all_stations($role) ? [$id] : [$id, $station_id];

    customer_update_existing($pdo, [
        'name' => $fullName,
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'],
        'last_name' => $data['last_name'],
        'contact_number' => $data['contact_number'],
        'phone' => $data['contact_number'],
        'address' => $data['address'],
        'customer_type' => $data['customer_type'],
        'type' => customer_legacy_billing_type($data['customer_type']),
        'status' => $data['status'],
        'account_status' => $data['status'],
        'vehicle_plate' => $data['vehicle_plate'],
        'plate_number' => $data['vehicle_plate'],
        'vehicle_make' => $data['vehicle_make'],
        'vehicle_brand' => $data['vehicle_make'],
        'vehicle_model' => $data['vehicle_model'],
        'vehicle_type' => $data['vehicle_type'],
        'credit_limit' => $data['credit_limit'],
        'updated_by' => $me['id'] ?? null,
    ], $whereSql, $whereValues, [
        'updated_at' => 'NOW()',
    ]);

    manager_audit('Update', "Updated customer {$fullName}", $id);
    echo json_encode(['success' => true, 'message' => 'Customer has been updated successfully.']);
}

function manager_deactivate_customer(): void {
    global $pdo, $station_id, $role, $me;

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $whereSql = customer_can_view_all_stations($role) ? 'id = ?' : 'id = ? AND station_id = ?';
    $whereValues = customer_can_view_all_stations($role) ? [$id] : [$id, $station_id];
    $updated = customer_update_existing($pdo, [
        'status' => 'inactive',
        'account_status' => 'inactive',
        'updated_by' => $me['id'] ?? null,
    ], $whereSql, $whereValues, [
        'updated_at' => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already inactive.');
    }

    manager_audit('Deactivate', "Deactivated customer ID {$id}", $id);
    echo json_encode(['success' => true, 'message' => 'Customer has been deactivated.']);
}

function manager_count_pending_requests(): int {
    global $pdo, $station_id, $role;
    if (!manager_has_table($pdo, 'customer_requests')) {
        return 0;
    }

    $where = ["LOWER(status) = 'pending'"];
    $params = [];
    if (!customer_can_view_all_stations($role)) {
        $where[] = 'station_id = ?';
        $params[] = $station_id;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_requests WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function manager_list_customer_requests(): void {
    global $pdo, $station_id, $role;

    if (!manager_has_table($pdo, 'customer_requests')) {
        echo json_encode(['success' => true, 'requests' => []]);
        return;
    }

    $where = ["LOWER(cr.status) = 'pending'"];
    $params = [];
    if (!customer_can_view_all_stations($role)) {
        $where[] = 'cr.station_id = ?';
        $params[] = $station_id;
    }

    $stmt = $pdo->prepare("
        SELECT
            cr.*,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS requested_by_name
        FROM customer_requests cr
        LEFT JOIN users u ON u.id = cr.requested_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cr.created_at DESC, cr.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function manager_fetch_request_for_review(int $requestId): array {
    global $pdo, $station_id, $role;
    $where = ['id = ?', "LOWER(status) = 'pending'"];
    $params = [$requestId];
    if (!customer_can_view_all_stations($role)) {
        $where[] = 'station_id = ?';
        $params[] = $station_id;
    }

    $stmt = $pdo->prepare('SELECT * FROM customer_requests WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new Exception('Customer request not found.');
    }
    return $request;
}

function manager_approve_customer_request(): void {
    global $pdo, $me;

    $requestId = (int)($_POST['id'] ?? 0);
    if ($requestId <= 0) {
        throw new Exception('Request ID is required.');
    }

    $request = manager_fetch_request_for_review($requestId);
    $customerStation = (int)$request['station_id'];
    if ($customerStation <= 0) {
        throw new Exception('Request has no station.');
    }

    $pdo->beginTransaction();
    try {
        $customerId = manager_generate_customer_id($customerStation);
        $type = in_array($request['customer_type'], ['walk-in', 'regular', 'credit'], true)
            ? $request['customer_type']
            : 'walk-in';
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ?? '') . ' ' . $request['last_name']);

        $newId = customer_insert_existing($pdo, [
            'customer_id' => $customerId,
            'station_id' => $customerStation,
            'name' => $fullName,
            'first_name' => $request['first_name'],
            'middle_name' => $request['middle_name'],
            'last_name' => $request['last_name'],
            'contact_number' => $request['contact_number'],
            'phone' => $request['contact_number'],
            'address' => $request['address'],
            'customer_type' => $type,
            'type' => customer_legacy_billing_type($type),
            'status' => 'active',
            'account_status' => 'active',
            'vehicle_plate' => strtoupper($request['vehicle_plate'] ?? ''),
            'plate_number' => strtoupper($request['vehicle_plate'] ?? ''),
            'vehicle_make' => $request['vehicle_make'] ?? '',
            'vehicle_brand' => $request['vehicle_make'] ?? '',
            'vehicle_model' => $request['vehicle_model'] ?? '',
            'vehicle_type' => $request['vehicle_type'] ?? '',
            'credit_limit' => 0,
            'outstanding_balance' => 0,
            'current_balance' => 0,
            'balance' => 0,
            'registered_by' => $me['id'] ?? null,
        ], [
            'registered_at' => 'NOW()',
            'created_at' => 'NOW()',
        ]);

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
        echo json_encode(['success' => true, 'message' => 'Customer request approved and customer record created.', 'customer_id' => $customerId]);
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
    echo json_encode(['success' => true, 'message' => 'Customer request rejected.']);
}
?>
