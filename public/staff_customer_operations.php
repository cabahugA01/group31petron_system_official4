<?php
ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'admin', 'manager', 'superadmin', 'developer'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
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
        case 'search':
        case 'get_customers':
            staff_search_customers();
            break;
        case 'request':
        case 'request_new_customer':
            staff_request_new_customer();
            break;
        case 'add':
        case 'add_customer':
        case 'update':
        case 'update_customer':
        case 'deactivate':
        case 'delete':
            echo json_encode(['success' => false, 'error' => 'Staff can only request new customers. Manager approval is required.']);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function staff_customer_exprs(): array {
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
        'vehicle_plate' => customer_vehicle_expr($pdo, 'vehicle_plate', 'c'),
        'vehicle_make' => customer_vehicle_expr($pdo, 'vehicle_make', 'c'),
        'vehicle_model' => customer_vehicle_expr($pdo, 'vehicle_model', 'c'),
        'vehicle_type' => customer_vehicle_expr($pdo, 'vehicle_type', 'c'),
        'credit_limit' => customer_credit_limit_expr($pdo, 'c'),
        'balance' => customer_balance_expr($pdo, 'c'),
    ];
}

function staff_search_customers(): void {
    global $pdo, $role, $station_id;

    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
    } catch (Throwable $e) {
        echo json_encode(['success' => true, 'customers' => []]);
        return;
    }

    $search = trim($_GET['search'] ?? $_GET['q'] ?? '');
    $type = trim($_GET['type'] ?? '');
    $expr = staff_customer_exprs();
    $where = ["{$expr['status']} = 'active'"];
    $params = [];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    if ($search !== '') {
        $where[] = "({$expr['customer_id']} LIKE ? OR {$expr['display_name']} LIKE ? OR {$expr['contact']} LIKE ? OR {$expr['vehicle_plate']} LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s);
    }
    if (in_array($type, ['walk-in', 'regular', 'credit'], true)) {
        $where[] = "{$expr['type']} = ?";
        $params[] = $type;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            {$expr['customer_id']} AS customer_id,
            {$expr['display_name']} AS full_name,
            {$expr['first_name']} AS first_name,
            {$expr['middle_name']} AS middle_name,
            {$expr['last_name']} AS last_name,
            {$expr['contact']} AS contact_number,
            {$expr['type']} AS customer_type,
            {$expr['vehicle_plate']} AS plate_number,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['balance']} AS balance
        FROM customers c
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$expr['display_name']} ASC
        LIMIT 100
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function staff_request_new_customer(): void {
    global $pdo, $station_id, $me;

    if ($station_id <= 0) {
        throw new Exception('A station assignment is required.');
    }

    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $type = trim($_POST['customer_type'] ?? 'walk-in');

    if ($firstName === '' || $lastName === '' || $contact === '') {
        throw new Exception('First name, last name, and contact number are required.');
    }
    if (!in_array($type, ['walk-in', 'regular', 'credit'], true)) {
        $type = 'walk-in';
    }

    $stmt = $pdo->prepare("
        INSERT INTO customer_requests (
            station_id,
            requested_by,
            first_name,
            middle_name,
            last_name,
            contact_number,
            address,
            customer_type,
            vehicle_plate,
            vehicle_make,
            vehicle_model,
            vehicle_type,
            request_reason,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $station_id,
        $me['id'] ?? null,
        $firstName,
        $middleName,
        $lastName,
        $contact,
        trim($_POST['address'] ?? ''),
        $type,
        strtoupper(trim($_POST['plate_no'] ?? $_POST['vehicle_plate'] ?? '')),
        trim($_POST['vehicle_make'] ?? ''),
        trim($_POST['vehicle_model'] ?? ''),
        trim($_POST['vehicle_type'] ?? ''),
        trim($_POST['request_reason'] ?? ''),
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'New customer request has been forwarded to the Manager for approval.',
        'request_id' => (int)$pdo->lastInsertId(),
    ]);
}
?>
