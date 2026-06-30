<?php
/**
 * ADMIN CUSTOMER OVERSIGHT OPERATIONS API
 * Strictly view-only, no modifications, with complete search/filters and paginated transaction history.
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

// Strictly Admin, SuperAdmin, Developer roles
if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            listCustomers();
            break;
        case 'view':
            viewCustomer();
            break;
        case 'transaction_history':
            getCustomerTransactionHistory();
            break;

        case 'log_document_access':
            logDocumentAccess();
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────
// LIST CUSTOMERS (WITH FILTERS AND SUMMARY STATS)
// ─────────────────────────────────────────────────────────────────────
function listCustomers() {
    global $pdo, $station_id;

    $search       = trim($_GET['search'] ?? '');
    $type         = trim($_GET['type'] ?? '');
    $status       = trim($_GET['status'] ?? '');
    $registeredBy = trim($_GET['registered_by'] ?? '');
    $dateRegFrom  = trim($_GET['date_reg_from'] ?? '');
    $dateRegTo    = trim($_GET['date_reg_to'] ?? '');
    $dateTxFrom   = trim($_GET['date_tx_from'] ?? '');
    $dateTxTo     = trim($_GET['date_tx_to'] ?? '');

    $where  = ['c.station_id = ?'];
    $params = [$station_id];

    if ($search !== '') {
        $where[] = "(CAST(c.id AS CHAR) LIKE ? OR c.name LIKE ? OR c.contact_number LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s);
    }

    if ($type !== '') {
        $where[] = "c.type = ?";
        $params[] = $type;
    }

    if ($status !== '') {
        $where[] = "c.status = ?";
        $params[] = $status;
    }

    if ($registeredBy !== '') {
        $where[] = "c.registered_by = ?";
        $params[] = (int)$registeredBy;
    }

    if ($dateRegFrom !== '') {
        $where[] = "DATE(c.created_at) >= ?";
        $params[] = $dateRegFrom;
    }
    if ($dateRegTo !== '') {
        $where[] = "DATE(c.created_at) <= ?";
        $params[] = $dateRegTo;
    }

    $whereClause = implode(' AND ', $where);

    // Fetch customers
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.id AS customer_id,
            c.name AS display_name,
            c.contact_number,
            c.type AS customer_type,
            c.status,
            c.verification_status,
            c.created_at AS registered_at,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS registered_by_name
        FROM customers c
        LEFT JOIN users u ON c.verified_by = u.id
        WHERE $whereClause
        ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    $rawCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch last transaction and perform PHP-side filtering for Date Last Transaction
    $filteredCustomers = [];
    foreach ($rawCustomers as $c) {
        $lastTxDate = null;

        // Fuel Transactions
        try {
            $q = $pdo->prepare("SELECT MAX(transaction_date) FROM fuel_transactions WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $station_id]);
            $d = $q->fetchColumn();
            if ($d) $lastTxDate = $lastTxDate ? max($lastTxDate, $d) : $d;
        } catch (Exception $e) {}

        // Merchandise Transactions
        try {
            $q = $pdo->prepare("SELECT MAX(transaction_date) FROM merchandise_transactions WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $station_id]);
            $d = $q->fetchColumn();
            if ($d) $lastTxDate = $lastTxDate ? max($lastTxDate, $d) : $d;
        } catch (Exception $e) {}

        // Job Orders
        try {
            $q = $pdo->prepare("SELECT MAX(created_at) FROM job_orders WHERE customer_id = ? AND station_id = ?");
            $q->execute([$c['id'], $station_id]);
            $d = $q->fetchColumn();
            if ($d) $lastTxDate = $lastTxDate ? max($lastTxDate, $d) : $d;
        } catch (Exception $e) {}

        // Last Transaction Date Filter
        if ($lastTxDate) {
            $txDateOnly = date('Y-m-d', strtotime($lastTxDate));
            if ($dateTxFrom !== '' && $txDateOnly < $dateTxFrom) continue;
            if ($dateTxTo !== '' && $txDateOnly > $dateTxTo) continue;
        } else {
            // If filtering by transaction date and customer has none, exclude
            if ($dateTxFrom !== '' || $dateTxTo !== '') continue;
        }

        $c['last_transaction_date'] = $lastTxDate;
        $filteredCustomers[] = $c;
    }

    // Dynamic Summary Stats (Calculated on ALL customers in station)
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_customers,
            SUM(CASE WHEN DATE(COALESCE(registered_at, created_at)) = CURDATE() THEN 1 ELSE 0 END) AS new_registered,
            SUM(CASE WHEN customer_type = 'regular' THEN 1 ELSE 0 END) AS regular_customers,
            SUM(CASE WHEN customer_type = 'fleet' THEN 1 ELSE 0 END) AS fleet_accounts,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_customers,
            SUM(CASE WHEN status IN ('inactive', 'suspended') THEN 1 ELSE 0 END) AS inactive_customers
        FROM customers
        WHERE station_id = ?
    ");
    $statsStmt->execute([$station_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_customers' => 0,
        'new_registered' => 0,
        'regular_customers' => 0,
        'fleet_accounts' => 0,
        'active_customers' => 0,
        'inactive_customers' => 0
    ];

    echo json_encode([
        'success'   => true,
        'customers' => $filteredCustomers,
        'stats'     => $stats
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// VIEW SINGLE CUSTOMER PROFILE (DETAILS & SUMMARIES)
// ─────────────────────────────────────────────────────────────────────
function viewCustomer() {
    global $pdo, $station_id;

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('Customer ID is required');

    $stmt = $pdo->prepare("
        SELECT c.*,
               CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS registered_by_name
        FROM customers c
        LEFT JOIN users u ON c.registered_by = u.id
        WHERE c.id = ? AND c.station_id = ?
    ");
    $stmt->execute([$id, $station_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) throw new Exception('Customer not found');

    // Clean up sensitive DB field values just in case
    unset($customer['password']);

    // Summary counts & financial totals
    $merchCount  = 0;
    $merchSpent  = 0.0;
    $joCount     = 0;
    $joSpent     = 0.0;
    $fuelCount   = 0;
    $fuelSpent   = 0.0;
    $lastTxDate  = null;

    // Merchandise transactions summary
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as tot, MAX(transaction_date) as last_d
            FROM merchandise_transactions
            WHERE customer_id = ? AND station_id = ?
        ");
        $q->execute([$id, $station_id]);
        $res = $q->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $merchCount = (int)$res['cnt'];
            $merchSpent = (float)$res['tot'];
            if ($res['last_d']) $lastTxDate = $res['last_d'];
        }
    } catch (Exception $e) {}

    // Job Orders summary
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(total_cost), 0) as tot, MAX(created_at) as last_d
            FROM job_orders
            WHERE customer_id = ? AND station_id = ?
        ");
        $q->execute([$id, $station_id]);
        $res = $q->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $joCount = (int)$res['cnt'];
            $joSpent = (float)$res['tot'];
            if ($res['last_d']) $lastTxDate = $lastTxDate ? max($lastTxDate, $res['last_d']) : $res['last_d'];
        }
    } catch (Exception $e) {}

    // Fuel transactions summary (added for complete spend picture)
    try {
        $q = $pdo->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as tot, MAX(transaction_date) as last_d
            FROM fuel_transactions
            WHERE customer_id = ? AND station_id = ?
        ");
        $q->execute([$id, $station_id]);
        $res = $q->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $fuelCount = (int)$res['cnt'];
            $fuelSpent = (float)$res['tot'];
            if ($res['last_d']) $lastTxDate = $lastTxDate ? max($lastTxDate, $res['last_d']) : $res['last_d'];
        }
    } catch (Exception $e) {}

    $summary = [
        'total_merchandise_txns' => $merchCount,
        'total_job_orders'        => $joCount,
        'total_fuel_txns'         => $fuelCount,
        'total_amount_spent'      => $merchSpent + $joSpent + $fuelSpent,
        'last_transaction_date'   => $lastTxDate
    ];

    echo json_encode([
        'success'  => true,
        'customer' => $customer,
        'summary'  => $summary
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// GET PAGINATED TRANSACTION HISTORY FOR PROFILE VIEW
// ─────────────────────────────────────────────────────────────────────
function getCustomerTransactionHistory() {
    global $pdo, $station_id;

    $id       = (int)($_GET['id'] ?? 0);
    $search   = trim($_GET['search'] ?? '');
    $module   = trim($_GET['module'] ?? '');
    $status   = trim($_GET['status'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');

    $limit    = max(10, min(100, (int)($_GET['limit'] ?? 10)));
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $offset   = ($page - 1) * $limit;

    if (!$id) throw new Exception('Customer ID is required');

    $allTx = [];

    // 1. Fetch Fuel Transactions
    if ($module === '' || $module === 'Fuel') {
        $where = ['ft.customer_id = ?', 'ft.station_id = ?'];
        $params = [$id, $station_id];

        if ($search !== '') {
            $where[] = "ft.transaction_id LIKE ?";
            $params[] = "%$search%";
        }
        if ($status !== '') {
            $where[] = "ft.status = ?";
            $params[] = $status;
        }
        if ($dateFrom !== '') {
            $where[] = "DATE(ft.transaction_date) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(ft.transaction_date) <= ?";
            $params[] = $dateTo;
        }

        $wClause = implode(' AND ', $where);
        try {
            $q = $pdo->prepare("
                SELECT ft.transaction_date AS txn_date,
                       ft.transaction_id   AS reference_no,
                       'Fuel'              AS module,
                       CONCAT(ft.fuel_type, ' — ', ft.liters_sold, 'L') AS description,
                       ft.total_amount     AS amount,
                       COALESCE(ft.status, 'Completed') AS status,
                       COALESCE(u.name, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), 'System') AS processed_by
                FROM fuel_transactions ft
                LEFT JOIN users u ON ft.staff_id = u.id
                WHERE $wClause
            ");
            $q->execute($params);
            $allTx = array_merge($allTx, $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
    }

    // 2. Fetch Merchandise Transactions
    if ($module === '' || $module === 'Merchandise') {
        $where = ['mt.customer_id = ?', 'mt.station_id = ?'];
        $params = [$id, $station_id];

        if ($search !== '') {
            $where[] = "mt.transaction_id LIKE ?";
            $params[] = "%$search%";
        }
        if ($status !== '') {
            $where[] = "mt.validation_status = ?";
            $params[] = $status;
        }
        if ($dateFrom !== '') {
            $where[] = "DATE(mt.transaction_date) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(mt.transaction_date) <= ?";
            $params[] = $dateTo;
        }

        $wClause = implode(' AND ', $where);
        try {
            $q = $pdo->prepare("
                SELECT mt.transaction_date AS txn_date,
                       mt.transaction_id   AS reference_no,
                       'Merchandise'       AS module,
                       CONCAT('Sale — ₱', FORMAT(mt.total_amount,2)) AS description,
                       mt.total_amount     AS amount,
                       COALESCE(mt.validation_status, 'Completed') AS status,
                       COALESCE(u.name, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), 'System') AS processed_by
                FROM merchandise_transactions mt
                LEFT JOIN users u ON mt.staff_id = u.id
                WHERE $wClause
            ");
            $q->execute($params);
            $allTx = array_merge($allTx, $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
    }

    // 3. Fetch Job Orders
    if ($module === '' || $module === 'Job Order') {
        $where = ['jo.customer_id = ?', 'jo.station_id = ?'];
        $params = [$id, $station_id];

        if ($search !== '') {
            $where[] = "(jo.job_order_id LIKE ? OR jo.job_order_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($status !== '') {
            $where[] = "jo.status = ?";
            $params[] = $status;
        }
        if ($dateFrom !== '') {
            $where[] = "DATE(jo.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(jo.created_at) <= ?";
            $params[] = $dateTo;
        }

        $wClause = implode(' AND ', $where);
        try {
            $q = $pdo->prepare("
                SELECT jo.created_at       AS txn_date,
                       COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS reference_no,
                       'Job Order'         AS module,
                       COALESCE(jo.service_type, 'Auto Service') AS description,
                       jo.total_cost       AS amount,
                       COALESCE(jo.status, 'Pending') AS status,
                       COALESCE(u.name, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), 'System') AS processed_by
                FROM job_orders jo
                LEFT JOIN users u ON jo.created_by = u.id
                WHERE $wClause
            ");
            $q->execute($params);
            $allTx = array_merge($allTx, $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
    }

    // Sort combined records descending by date
    usort($allTx, function($a, $b) {
        return strtotime($b['txn_date']) - strtotime($a['txn_date']);
    });

    $totalRecords = count($allTx);
    $paginatedTx  = array_slice($allTx, $offset, $limit);
    $totalPages   = ceil($totalRecords / $limit);

    echo json_encode([
        'success'      => true,
        'history'      => $paginatedTx,
        'total_rows'   => $totalRecords,
        'total_pages'  => $totalPages,
        'current_page' => $page,
        'limit'        => $limit
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// LOG DOCUMENT ACCESS FOR AUDIT
// ─────────────────────────────────────────────────────────────────────
function logDocumentAccess() {
    global $pdo, $station_id;

    $id      = (int)($_POST['id'] ?? 0);
    $docType = trim($_POST['doc_type'] ?? '');

    if (!$id || !in_array($docType, ['gov_id', 'cr'])) {
        throw new Exception('Invalid document access parameters');
    }

    $stmt = $pdo->prepare("SELECT name FROM customers WHERE id = ? AND station_id = ?");
    $stmt->execute([$id, $station_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($c) {
        write_audit_log($pdo, 'View', "Admin viewed $docType document for customer: {$c['name']}", 'customers', $id, 'customer');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Customer record not found']);
    }
}
?>
