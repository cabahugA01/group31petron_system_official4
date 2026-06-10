<?php
/**
 * Admin Customer Management
 * Five-section module: Master List | Balances | Receivable | History | Oversight | Audit Trail
 * Admin — Station-scoped view (assigned station only)
 * SuperAdmin/Developer — Global franchise-wide view (all stations)
 */

// ── Bootstrap ─────────────────────────────────────────────
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Role gate
if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: dashboard.php');
    exit;
}

// ── Section routing ────────────────────────────────────────
$valid_sections = ['list', 'balances', 'receivable', 'history', 'oversight', 'audit'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'list';

// Oversight section is accessible to Admin & SuperAdmin

// Page ID for sidebar sub-item highlighting
$page_id = match($section) {
    'balances'   => 'adm_cust_balances',
    'history'    => 'adm_cust_history',
    'oversight'  => 'adm_cust_oversight',
    'audit'      => 'adm_cust_audit',
    default      => 'adm_cust_list',
};

// ── Ensure required customer columns exist ─────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $required_cols = [
        'contact_number' => "VARCHAR(50) NULL",
        'id_number'      => "VARCHAR(100) NULL",
        'credit_limit'   => "DECIMAL(12,2) DEFAULT 0.00",
        'current_balance'=> "DECIMAL(12,2) DEFAULT 0.00",
    ];
    foreach ($required_cols as $col => $def) {
        if (!in_array($col, $cols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN $col $def");
        }
    }
} catch (Exception $e) { /* silent */ }

// ── POST: credit-limit adjustment ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Block Admin from editing customers belonging to other stations for standard actions (Oversight actions bypass this)
    if ($role === 'admin' && in_array($_POST['action'], ['adjust_credit_limit', 'toggle_status'])) {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if ($cid > 0) {
            $chk_station = (int)adm_cust_val($pdo, "SELECT station_id FROM customers WHERE id = ?", [$cid], 0);
            if ($chk_station !== $station_id) {
                echo json_encode(['success' => false, 'error' => 'Permission denied: Customer belongs to another station.']);
                exit;
            }
        }
    }

    if ($_POST['action'] === 'adjust_credit_limit') {
        $cid   = (int)($_POST['customer_id'] ?? 0);
        $limit = (float)($_POST['credit_limit'] ?? 0);
        $note  = trim($_POST['note'] ?? '');
        try {
            // Global — no station_id constraint (Admin can adjust any customer)
            $stmt = $pdo->prepare("UPDATE customers SET credit_limit=? WHERE id=?");
            $stmt->execute([$limit, $cid]);
            write_audit_log($pdo, 'Update', "Admin adjusted credit limit for Customer #$cid → ₱" . number_format($limit, 2) . ($note ? " | Note: $note" : ''), 'customers', $cid, 'success');
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'toggle_status') {
        $cid    = (int)($_POST['customer_id'] ?? 0);
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
        try {
            // Global — no station_id constraint
            $stmt = $pdo->prepare("UPDATE customers SET status=? WHERE id=?");
            $stmt->execute([$status, $cid]);
            write_audit_log($pdo, 'Update', "Admin changed status of Customer #$cid → $status", 'customers', $cid, 'success');
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'reassign_station') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        $new_station_id = (int)($_POST['new_station_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE customers SET station_id=? WHERE id=?");
            $stmt->execute([$new_station_id, $cid]);
            $station_name = adm_cust_val($pdo, "SELECT name FROM stations WHERE id=?", [$new_station_id], 'Unknown');
            write_audit_log($pdo, 'Update', "Admin re-assigned Customer #$cid → Station: $station_name (ID: $new_station_id)", 'customers', $cid, 'success');
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'archive_customer') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        try {
            // Soft delete by setting status to 'archived'
            $stmt = $pdo->prepare("UPDATE customers SET status='archived' WHERE id=?");
            $stmt->execute([$cid]);
            write_audit_log($pdo, 'Delete', "Admin archived Customer #$cid", 'customers', $cid, 'success');
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Master Customer List CSV/Excel Export Handler ───────────────────────────
if ($section === 'list' && isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $is_excel = ($_GET['export'] === 'excel');
    $where  = "WHERE 1=1";
    $params = [];
    if ($role === 'admin') {
        $where .= " AND c.station_id = :admin_station_id";
        $params[':admin_station_id'] = $station_id;
    }
    if ($search !== '') {
        $where .= " AND (c.name LIKE :q OR c.contact_number LIKE :q OR c.id_number LIKE :q OR c.email LIKE :q)";
        $params[':q'] = "%$search%";
    }
    if ($status_filter !== 'all') {
        $where .= " AND c.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($station_filter > 0 && $role !== 'admin') {
        $where .= " AND c.station_id = :stn";
        $params[':stn'] = $station_filter;
    }
    
    $rows = adm_cust_rows($pdo,
        "SELECT c.id, c.name, c.contact_number, c.id_number, c.email,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                c.status, c.created_at, s.name AS station_name
         FROM customers c
         LEFT JOIN stations s ON s.id = c.station_id
         $where
         ORDER BY c.name ASC", $params);
         
    if (ob_get_level() > 0) ob_end_clean();
    $filename = 'admin_customer_list_' . date('Y-m-d') . ($is_excel ? '.xls' : '.csv');
    if ($is_excel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Customer Management Directory']);
    fputcsv($output, ['Station Filter:', $role === 'admin' ? 'Station #' . $station_id : ($station_filter > 0 ? 'Station #' . $station_filter : 'All Stations')]);
    fputcsv($output, ['Exported By:', $me['name'] ?? $me['username'] ?? 'Admin']);
    fputcsv($output, ['Export Date:', date('F d, Y h:i A')]);
    fputcsv($output, ['Total Records:', count($rows)]);
    fputcsv($output, []);
    
    fputcsv($output, ['ID', 'Customer Name', 'Station', 'Contact Number', 'ID Number', 'Email', 'Outstanding Balance', 'Credit Limit', 'Status']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['station_name'] ?? '—',
            $row['contact_number'] ?? '—',
            $row['id_number'] ?? '—',
            $row['email'] ?? '—',
            number_format((float)$row['outstanding_balance'], 2),
            number_format((float)$row['credit_limit'], 2),
            ucfirst($row['status'] ?? 'active')
        ]);
    }
    fclose($output);
    write_audit_log($pdo, 'Export Customer List', "Admin exported customer list directory to " . strtoupper($_GET['export']), 'customers', 0, 'report');
    exit;
}

// ── Customer History CSV/Excel Export Handler ───────────────────────────────
if ($section === 'history' && isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $is_excel = ($_GET['export'] === 'excel');
    $hist_customer_id = (int)($_GET['cid'] ?? 0);
    
    if ($hist_customer_id > 0) {
        if ($role === 'admin') {
            $chk_station = (int)adm_cust_val($pdo, "SELECT station_id FROM customers WHERE id = ?", [$hist_customer_id], 0);
            if ($chk_station !== $station_id) {
                $hist_customer_id = 0;
            }
        }
    }
    
    if ($hist_customer_id > 0) {
        $cust_name = adm_cust_val($pdo, "SELECT name FROM customers WHERE id=?", [$hist_customer_id], 'Customer');
        
        $jo_cols_adm  = adm_cust_rows($pdo, "SHOW COLUMNS FROM job_orders", []);
        $jo_col_names = array_column($jo_cols_adm, 'Field');
        $jo_cust_cond = in_array('credit_customer_id', $jo_col_names) ? '(customer_id=? OR credit_customer_id=?)' : 'customer_id=?';
        $jo_params = in_array('credit_customer_id', $jo_col_names) ? [$hist_customer_id, $hist_customer_id] : [$hist_customer_id];
        
        $mt_cols_adm  = adm_cust_rows($pdo, "SHOW COLUMNS FROM merchandise_transactions", []);
        $mt_col_names = array_column($mt_cols_adm, 'Field');
        $mt_cid_col   = in_array('credit_customer_id', $mt_col_names) ? 'credit_customer_id' : (in_array('customer_id', $mt_col_names) ? 'customer_id' : null);
        
        $exp_rows = [];
        // Job Orders
        try {
            $s = $pdo->prepare("
                SELECT 'Job Order' AS type, id, COALESCE(created_at, updated_at) AS txn_date,
                       COALESCE(total_amount, estimated_cost, 0) AS total_amount,
                       COALESCE(payment_method, '—') AS payment_method,
                       status, COALESCE(remarks, service_description, service_type, '') AS notes
                FROM job_orders WHERE $jo_cust_cond ORDER BY txn_date DESC LIMIT 200
            ");
            $s->execute($jo_params);
            $exp_rows = array_merge($exp_rows, $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
        
        // Merchandise
        if ($mt_cid_col) {
            try {
                $mt_date = in_array('transaction_date', $mt_col_names) ? 'COALESCE(transaction_date, created_at)' : 'created_at';
                $s = $pdo->prepare("
                    SELECT 'Merchandise' AS type, id, $mt_date AS txn_date,
                           COALESCE(total_amount, 0) AS total_amount,
                           COALESCE(payment_method, '—') AS payment_method,
                           COALESCE(validation_status, status, '—') AS status, NULL AS notes
                    FROM merchandise_transactions WHERE {$mt_cid_col}=? ORDER BY txn_date DESC LIMIT 200
                ");
                $s->execute([$hist_customer_id]);
                $exp_rows = array_merge($exp_rows, $s->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}
        }
        
        // Payments
        try {
            $s = $pdo->prepare("
                SELECT 'Payment' AS type, id, created_at AS txn_date,
                       amount AS total_amount, 'Credit Payment' AS payment_method,
                       'Paid' AS status, description AS notes
                FROM customer_credit_transactions WHERE customer_id=? AND transaction_type='Payment' ORDER BY created_at DESC LIMIT 100
            ");
            $s->execute([$hist_customer_id]);
            $exp_rows = array_merge($exp_rows, $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
        
        usort($exp_rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
        $exp_rows = array_slice($exp_rows, 0, 300);
        
        if (ob_get_level() > 0) ob_end_clean();
        $filename = 'admin_customer_history_' . date('Y-m-d') . ($is_excel ? '.xls' : '.csv');
        if ($is_excel) {
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
        }
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Customer History Audit Report']);
        fputcsv($output, ['Customer:', $cust_name]);
        fputcsv($output, ['Exported By:', $me['name'] ?? $me['username'] ?? 'Admin']);
        fputcsv($output, ['Export Date:', date('F d, Y h:i A')]);
        fputcsv($output, ['Total Records:', count($exp_rows)]);
        fputcsv($output, []);
        
        fputcsv($output, ['Date/Time', 'Type', 'ID/Ref', 'Amount (₱)', 'Payment Method', 'Status', 'Notes']);
        foreach ($exp_rows as $er) {
            fputcsv($output, [
                date('M d, Y h:i A', strtotime($er['txn_date'])),
                $er['type'],
                $er['id'],
                number_format((float)$er['total_amount'], 2),
                $er['payment_method'],
                ucfirst($er['status']),
                $er['notes'] ?? '—'
            ]);
        }
        fclose($output);
        write_audit_log($pdo, 'Export Customer History', "Admin exported customer history report to " . strtoupper($_GET['export']) . " for customer: $cust_name", 'customers', $hist_customer_id, 'report');
        exit;
    } else {
        $_SESSION['flash_err'] = 'No customer selected to export history.';
        header('Location: admin_customer_management.php?section=history');
        exit;
    }
}

// ── Helpers ────────────────────────────────────────────────
function adm_cust_val(PDO $p, string $sql, array $args = [], $default = 0) {
    try { $s = $p->prepare($sql); $s->execute($args); return $s->fetchColumn() ?? $default; }
    catch (Exception $e) { return $default; }
}
function adm_cust_rows(PDO $p, string $sql, array $args = []): array {
    try { $s = $p->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Exception $e) { return []; }
}

// ── DATA: Customer List ────────────────────────────────────
$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$station_filter = (int)($_GET['stn'] ?? 0);
if ($role === 'admin') {
    $station_filter = $station_id;
}
$customers = [];
if ($section === 'list') {
    $where  = "WHERE 1=1";
    $params = [];
    if ($role === 'admin') {
        $where .= " AND c.station_id = :admin_station_id";
        $params[':admin_station_id'] = $station_id;
    }
    if ($search !== '') {
        $where .= " AND (c.name LIKE :q OR c.contact_number LIKE :q OR c.id_number LIKE :q OR c.email LIKE :q)";
        $params[':q'] = "%$search%";
    }
    if ($status_filter !== 'all') {
        $where .= " AND c.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($station_filter > 0 && $role !== 'admin') {
        $where .= " AND c.station_id = :stn";
        $params[':stn'] = $station_filter;
    }
    $customers = adm_cust_rows($pdo,
        "SELECT c.id, c.name, c.contact_number, c.id_number, c.email,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                c.status, c.created_at, s.name AS station_name
         FROM customers c
         LEFT JOIN stations s ON s.id = c.station_id
         $where
         ORDER BY c.name ASC", $params);
}

// ── DATA: Balances Oversight ───────────────────────────────
$balance_customers = [];
$overdue_count = 0;
if ($section === 'balances') {
    $bal_where = "";
    $bal_params = [];
    if ($role === 'admin') {
        $bal_where = "WHERE c.station_id = ?";
        $bal_params = [$station_id];
    }
    $balance_customers = adm_cust_rows($pdo,
        "SELECT c.id, c.name, c.contact_number,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                c.status,
                s.name AS station_name,
                CASE
                    WHEN COALESCE(c.credit_limit,0) > 0
                     AND COALESCE(c.current_balance,c.balance,0) >= COALESCE(c.credit_limit,0)
                    THEN 'overdue'
                    WHEN COALESCE(c.current_balance,c.balance,0) > 0 THEN 'has_balance'
                    ELSE 'clear'
                END AS balance_flag
         FROM customers c
         LEFT JOIN stations s ON s.id = c.station_id
         $bal_where
         ORDER BY outstanding_balance DESC",
        $bal_params);
    $overdue_count = count(array_filter($balance_customers, fn($c) => $c['balance_flag'] === 'overdue'));
}

// ── DATA: Accounts Receivable ──────────────────────────────
$ar_rows     = [];
$total_ar    = 0;
$collected   = 0;
if ($section === 'receivable') {
    $ar_where = "WHERE COALESCE(c.current_balance, c.balance, 0) > 0";
    $ar_params = [];
    if ($role === 'admin') {
        $ar_where .= " AND c.station_id = ?";
        $ar_params = [$station_id];
    }
    $ar_rows = adm_cust_rows($pdo,
        "SELECT c.id, c.name, c.contact_number,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                (SELECT COALESCE(SUM(amount),0)
                   FROM credit_payments cp
                  WHERE cp.customer_id = c.id) AS total_paid,
                c.status, s.name AS station_name
         FROM customers c
         LEFT JOIN stations s ON s.id = c.station_id
         $ar_where
         ORDER BY outstanding_balance DESC",
        $ar_params);
    $total_ar  = array_sum(array_column($ar_rows, 'outstanding_balance'));
    
    $collected_sql = "SELECT COALESCE(SUM(cp.amount),0) FROM credit_payments cp";
    $collected_params = [];
    if ($role === 'admin') {
        $collected_sql .= " WHERE cp.station_id = ?";
        $collected_params = [$station_id];
    }
    $collected = adm_cust_val($pdo, $collected_sql, $collected_params);
}

// ── DATA: Customer History ─────────────────────────────────
$hist_customer_id = (int)($_GET['cid'] ?? 0);
$hist_customers   = [];
$hist_rows        = [];
if ($section === 'history') {
    $hist_cust_sql = "SELECT c.id, c.name, s.name AS station_name
         FROM customers c
         LEFT JOIN stations s ON s.id = c.station_id
         WHERE c.status != 'archived'";
    $hist_cust_params = [];
    if ($role === 'admin') {
        $hist_cust_sql .= " AND c.station_id = ?";
        $hist_cust_params = [$station_id];
    }
    $hist_cust_sql .= " ORDER BY c.name ASC";
    $hist_customers = adm_cust_rows($pdo, $hist_cust_sql, $hist_cust_params);

    if ($hist_customer_id > 0) {
        if ($role === 'admin') {
            $chk_station = (int)adm_cust_val($pdo, "SELECT station_id FROM customers WHERE id = ?", [$hist_customer_id], 0);
            if ($chk_station !== $station_id) {
                $hist_customer_id = 0;
            }
        }
    }

    if ($hist_customer_id > 0) {
        // Detect columns to handle schema variations
        $jo_cols_adm  = adm_cust_rows($pdo, "SHOW COLUMNS FROM job_orders", []);
        $jo_col_names = array_column($jo_cols_adm, 'Field');
        $jo_cust_cond = in_array('credit_customer_id', $jo_col_names)
            ? '(customer_id=? OR credit_customer_id=?)'
            : 'customer_id=?';
        $jo_params = in_array('credit_customer_id', $jo_col_names)
            ? [$hist_customer_id, $hist_customer_id]
            : [$hist_customer_id];

        $mt_cols_adm  = adm_cust_rows($pdo, "SHOW COLUMNS FROM merchandise_transactions", []);
        $mt_col_names = array_column($mt_cols_adm, 'Field');
        $mt_cid_col   = in_array('credit_customer_id', $mt_col_names) ? 'credit_customer_id'
                      : (in_array('customer_id', $mt_col_names) ? 'customer_id' : null);

        // Build history from job_orders + merchandise_transactions
        $hist_rows = [];
        // Job Orders
        try {
            $s = $pdo->prepare("
                SELECT 'Job Order' AS type, id,
                       COALESCE(created_at, updated_at) AS txn_date,
                       COALESCE(total_amount, estimated_cost, 0) AS total_amount,
                       COALESCE(payment_method, '—') AS payment_method,
                       status, COALESCE(remarks, service_description, service_type, '') AS notes
                FROM job_orders
                WHERE $jo_cust_cond
                ORDER BY txn_date DESC LIMIT 200
            ");
            $s->execute($jo_params);
            $hist_rows = array_merge($hist_rows, $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
        // Merchandise Transactions
        if ($mt_cid_col) {
            try {
                $mt_date = in_array('transaction_date', $mt_col_names) ? 'COALESCE(transaction_date, created_at)' : 'created_at';
                $s = $pdo->prepare("
                    SELECT 'Merchandise' AS type, id, $mt_date AS txn_date,
                           COALESCE(total_amount, 0) AS total_amount,
                           COALESCE(payment_method, '—') AS payment_method,
                           COALESCE(validation_status, status, '—') AS status, NULL AS notes
                    FROM merchandise_transactions
                    WHERE {$mt_cid_col}=?
                    ORDER BY txn_date DESC LIMIT 200
                ");
                $s->execute([$hist_customer_id]);
                $hist_rows = array_merge($hist_rows, $s->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}
        }
        // Payments from customer_credit_transactions
        try {
            $s = $pdo->prepare("
                SELECT 'Payment' AS type, id, created_at AS txn_date,
                       amount AS total_amount, 'Credit Payment' AS payment_method,
                       'Paid' AS status, description AS notes
                FROM customer_credit_transactions
                WHERE customer_id=? AND transaction_type='Payment'
                ORDER BY created_at DESC LIMIT 100
            ");
            $s->execute([$hist_customer_id]);
            $hist_rows = array_merge($hist_rows, $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}

        // Sort merged results by date desc
        usort($hist_rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
        $hist_rows = array_slice($hist_rows, 0, 300);
    }
}

// ── DATA: Customer Oversight — GLOBAL ──────────────────────
$oversight_customers = [];
$all_stations = [];
if ($section === 'oversight') {
    $oversight_customers = adm_cust_rows($pdo,
        "SELECT c.id, c.name, c.contact_number, c.email, c.status,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                c.station_id, s.name AS station_name,
                c.created_at, c.updated_at
         FROM customers c
         LEFT JOIN stations s ON s.id = c.station_id
         ORDER BY s.name ASC, c.name ASC",
        []);

    $all_stations = adm_cust_rows($pdo,
        "SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name ASC",
        []);
}

// ── DATA: Audit Trail — Customer actions ────────────────────
$audit_rows = [];
$audit_search = trim($_GET['aq'] ?? '');
$audit_date_from = $_GET['adf'] ?? '';
$audit_date_to   = $_GET['adt'] ?? '';
if ($section === 'audit') {
    $aw = "WHERE al.entity_type IN ('customers','customer')";
    $ap = [];
    if ($role === 'admin') {
        $aw .= " AND u.station_id = ?";
        $ap[] = $station_id;
    }
    if ($audit_search !== '') {
        $aw .= " AND (al.action_details LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)";
        $ap[] = "%$audit_search%";
        $ap[] = "%$audit_search%";
        $ap[] = "%$audit_search%";
    }
    if ($audit_date_from !== '') {
        $aw .= " AND DATE(al.created_at) >= ?";
        $ap[] = $audit_date_from;
    }
    if ($audit_date_to !== '') {
        $aw .= " AND DATE(al.created_at) <= ?";
        $ap[] = $audit_date_to;
    }
    $audit_rows = adm_cust_rows($pdo,
        "SELECT al.id, al.action_type, al.action_details, al.entity_type, al.entity_id,
                al.log_type, al.status, al.created_at,
                COALESCE(u.full_name, u.username, 'System') AS actor,
                u.role AS actor_role,
                s.name AS station_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN stations s ON s.id = u.station_id
         $aw
         ORDER BY al.created_at DESC
         LIMIT 300",
        $ap);
}

// ── Flash messages ─────────────────────────────────────────
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ── All stations for filter dropdowns ──────────────────────
$all_stations_list = adm_cust_rows($pdo,
    "SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name ASC",
    []);

include __DIR__ . '/../partials/header.php';
?>
<style>
:root {
    --adm-blue:    #002F6C;
    --adm-red:     #CC0000;
    --adm-success: #28a745;
    --adm-warning: #ffc107;
    --adm-danger:  #dc3545;
    --adm-info:    #17a2b8;
    --adm-gray:    #f8f9fa;
    --adm-border:  #dee2e6;
}

/* ── Action Buttons (Aligned with other Admin modules) ─── */
.action-btn {
    font-size: 12px;
    padding: 5px 8px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: all .15s;
    font-weight: 600;
    width: 100px;
    text-decoration: none;
}
.action-btn:hover {
    filter: brightness(.9);
    transform: translateY(-1px);
}
.btn-edit { background: #002F70; color: #fff; }
.btn-view { background: #28a745; color: #fff; }
.btn-danger { background: #dc3545; color: #fff; }
.btn-success { background: #28a745; color: #fff; }

/* ── Cards / KPI ─────────────────────────────────────────── */
.acm-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.acm-kpi {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border-left: 4px solid var(--adm-blue);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.acm-kpi.danger   { border-left-color: var(--adm-danger); }
.acm-kpi.warning  { border-left-color: var(--adm-warning); }
.acm-kpi.success  { border-left-color: var(--adm-success); }
.acm-kpi.info     { border-left-color: var(--adm-info); }
.acm-kpi-value    { font-size: 26px; font-weight: 800; color: var(--adm-blue); }
.acm-kpi.danger  .acm-kpi-value  { color: var(--adm-danger); }
.acm-kpi.warning .acm-kpi-value  { color: #b8860b; }
.acm-kpi.success .acm-kpi-value  { color: var(--adm-success); }
.acm-kpi.info    .acm-kpi-value  { color: var(--adm-info); }
.acm-kpi-label    { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: .5px; }

/* ── Table ───────────────────────────────────────────────── */
.acm-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    overflow: hidden;
    margin-bottom: 24px;
}
.acm-card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.acm-card-head h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--adm-blue);
    text-transform: uppercase;
    letter-spacing: .4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.acm-table-wrap { overflow-x: auto; }
.acm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.acm-table th {
    background: var(--adm-blue);
    color: #fff;
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.acm-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.acm-table tr:hover td { background: #f8faff; }
.acm-table tr:last-child td { border-bottom: none; }

/* ── Status badges ───────────────────────────────────────── */
.badge-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.badge-active   { background:#d4edda; color:#155724; }
.badge-inactive { background:#f8d7da; color:#721c24; }
.badge-overdue  { background:#f8d7da; color:#721c24; }
.badge-balance  { background:#fff3cd; color:#856404; }
.badge-clear    { background:#d4edda; color:#155724; }

/* ── Search / filter bar ─────────────────────────────────── */
.acm-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.acm-toolbar input[type=text],
.acm-toolbar select {
    padding: 7px 12px;
    border: 1px solid var(--adm-border);
    border-radius: 6px;
    font-size: 13px;
    color: #333;
    background: #fff;
    min-width: 180px;
}
.acm-toolbar input[type=text]:focus,
.acm-toolbar select:focus {
    outline: none;
    border-color: var(--adm-blue);
    box-shadow: 0 0 0 3px rgba(0,47,108,.08);
}
.btn-acm {
    padding: 7px 14px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .2s;
}
.btn-acm-primary { background: var(--adm-blue); color: #fff; }
.btn-acm-primary:hover { background: #001f4d; }
.btn-acm-danger  { background: var(--adm-danger); color: #fff; }
.btn-acm-success { background: var(--adm-success); color: #fff; }
.btn-acm-sm { padding: 4px 10px; font-size: 12px; }
.btn-acm-outline {
    background: #fff;
    border: 1px solid var(--adm-blue);
    color: var(--adm-blue);
}
.btn-acm-outline:hover { background: var(--adm-blue); color: #fff; }

/* ── Modals ──────────────────────────────────────────────── */
.acm-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 9000;
    align-items: center;
    justify-content: center;
}
.acm-modal-overlay.open { display: flex; }
.acm-modal {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    width: 420px;
    max-width: 96vw;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
}
.acm-modal h3 { margin: 0 0 18px; color: var(--adm-blue); font-size: 16px; }
.acm-modal label { font-size: 12px; font-weight: 600; color: #555; display: block; margin-bottom: 4px; }
.acm-modal input, .acm-modal textarea, .acm-modal select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--adm-border);
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 14px;
    box-sizing: border-box;
}
.acm-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }

/* ── Flash messages ──────────────────────────────────────── */
.acm-flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.acm-flash-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; border-left:4px solid #28a745; }
.acm-flash-err { background:#fee2e2; color:#7f1d1d; border:1px solid #fca5a5; border-left:4px solid #dc3545; }

/* ── Progress bar for credit utilisation ─────────────────── */
.credit-bar { height: 6px; border-radius: 3px; background: #e9ecef; margin-top: 4px; }
.credit-bar-fill { height: 100%; border-radius: 3px; background: var(--adm-success); transition: width .3s; }
.credit-bar-fill.warn  { background: var(--adm-warning); }
.credit-bar-fill.over  { background: var(--adm-danger); }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
    .acm-tabs { padding: 0 8px; }
    .acm-tab  { padding: 10px 12px; font-size: 12px; }
    .acm-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div style="max-width:1400px;margin:0 auto;padding:0 0 40px;">

    <!-- Page header -->
    <div class="page-head" style="margin-bottom:20px;display:block;">
        <h1 style="font-size:22px;font-weight:800;color:var(--adm-blue);margin:0 0 8px;">
            <i class="fas fa-users" style="margin-right:10px;"></i>Customer Management
        </h1>
        <p class="page-subtitle" style="margin:0;font-size:13px;color:#666;line-height:1.5;">
            <i class="fas fa-building" style="color:var(--adm-blue);margin-right:4px;"></i>
            <?php
            $section_descriptions = [
                'list'       => 'Global access to all customer profiles across stations.',
                'balances'   => 'Monitor receivables and outstanding balances across the franchise.',
                'receivable' => 'Track accounts receivable and payment collections franchise-wide.',
                'history'    => 'View full transaction history across all stations.',
                'oversight'  => 'Manage customer records (assign/re-map, delete/archive inactive).',
                'audit'      => 'Full logs of staff and manager actions for accountability and compliance.',
            ];
            echo $section_descriptions[$section] ?? 'Global franchise view — all stations — customer profiles, balances, receivables &amp; audit trail';
            ?>
        </p>
    </div>

    <!-- Flash messages -->
    <?php if ($flash_ok): ?>
        <div class="acm-flash acm-flash-ok"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($flash_ok); ?></div>
    <?php endif; ?>
    <?php if ($flash_err): ?>
        <div class="acm-flash acm-flash-err"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($flash_err); ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         SECTION 1: CUSTOMER LIST
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'list'): ?>

    <?php
    $total_customers  = count($customers);
    $active_count     = count(array_filter($customers, fn($c) => strtolower($c['status']) === 'active'));
    $inactive_count   = $total_customers - $active_count;
    $with_balance     = count(array_filter($customers, fn($c) => (float)$c['outstanding_balance'] > 0));
    ?>

    <!-- KPI row -->
    <div class="acm-kpi-grid">
        <div class="acm-kpi">
            <div class="acm-kpi-value"><?php echo number_format($total_customers); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-users"></i> Total Customers</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value"><?php echo number_format($active_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-user-check"></i> Active</div>
        </div>
        <div class="acm-kpi danger">
            <div class="acm-kpi-value"><?php echo number_format($inactive_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-user-slash"></i> Inactive</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value"><?php echo number_format($with_balance); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-exclamation-triangle"></i> With Balances</div>
        </div>
    </div>

    <!-- Search & filter -->
    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-list-ul"></i> <?php echo $role === 'admin' ? 'Customer List' : 'Global Customer List'; ?></h2>
            <form method="get" action="" style="margin:0;">
                <input type="hidden" name="section" value="list">
                <div class="acm-toolbar">
                    <input type="text" name="q" placeholder="Search name / contact / ID…"
                           value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status">
                        <option value="all"      <?php echo $status_filter === 'all'      ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <?php if ($role !== 'admin'): ?>
                    <select name="stn">
                        <option value="0">All Stations</option>
                        <?php foreach ($all_stations_list as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $station_filter === (int)$st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button type="submit" class="btn-acm btn-acm-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <?php if ($search || $status_filter !== 'all' || $station_filter > 0): ?>
                        <a href="?section=list" class="btn-acm btn-acm-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                    <a href="?section=list&export=csv&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&stn=<?php echo $station_filter; ?>" class="btn-acm" style="background:#28a745;color:#fff;text-decoration:none;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <a href="?section=list&export=excel&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&stn=<?php echo $station_filter; ?>" class="btn-acm" style="background:#1f7a3e;color:#fff;text-decoration:none;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <button type="button" onclick="printAdminListPDF()" class="btn-acm" style="background:#dc3545;color:#fff;border:none;cursor:pointer;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                </div>
            </form>
        </div>

        <div class="acm-table-wrap">
            <?php if (empty($customers)): ?>
                <div style="padding:40px;text-align:center;color:#999;">
                    <i class="fas fa-users" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                    No customers found.
                </div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer Name</th>
                        <?php if ($role !== 'admin'): ?><th>Station</th><?php endif; ?>
                        <th>Contact</th>
                        <th>Outstanding Balance</th>
                        <th>Credit Limit</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $i => $c):
                    $bal   = (float)$c['outstanding_balance'];
                    $limit = (float)$c['credit_limit'];
                    $util  = ($limit > 0) ? min(100, round($bal / $limit * 100)) : 0;
                    $bar_class = $util >= 100 ? 'over' : ($util >= 80 ? 'warn' : '');
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;color:#333;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <?php if ($role !== 'admin'): ?><td style="font-size:12px;color:var(--adm-blue);font-weight:600;"><?php echo htmlspecialchars($c['station_name'] ?? '—'); ?></td><?php endif; ?>
                    <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:<?php echo $bal > 0 ? '#dc3545' : '#28a745'; ?>;">
                        ₱<?php echo number_format($bal, 2); ?>
                    </td>
                    <td>
                        <div>₱<?php echo number_format($limit, 2); ?></div>
                        <?php if ($limit > 0): ?>
                            <div class="credit-bar" title="<?php echo $util; ?>% utilised">
                                <div class="credit-bar-fill <?php echo $bar_class; ?>"
                                     style="width:<?php echo $util; ?>%;"></div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge-status <?php echo strtolower($c['status']) === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo ucfirst(htmlspecialchars($c['status'] ?? 'unknown')); ?>
                        </span>
                    </td>
                    <td style="color:#666;font-size:12px;"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end;">
                            <button class="action-btn btn-edit"
                                    onclick="openCreditModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', <?php echo $limit; ?>)"
                                    title="Adjust Credit Limit">
                                <i class="fas fa-sliders-h"></i> Adjust
                            </button>
                            <button class="action-btn <?php echo strtolower($c['status']) === 'active' ? 'btn-danger' : 'btn-success'; ?>"
                                    onclick="toggleStatus(<?php echo $c['id']; ?>, '<?php echo strtolower($c['status']) === 'active' ? 'inactive' : 'active'; ?>')"
                                    title="<?php echo strtolower($c['status']) === 'active' ? 'Deactivate' : 'Reactivate'; ?>">
                                <i class="fas <?php echo strtolower($c['status']) === 'active' ? 'fa-times' : 'fa-check'; ?>"></i> 
                                <?php echo strtolower($c['status']) === 'active' ? 'Deactivate' : 'Activate'; ?>
                            </button>
                            <a href="?section=history&cid=<?php echo $c['id']; ?>"
                               class="action-btn btn-view" title="View History">
                                <i class="fas fa-history"></i> History
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end list */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 2: CUSTOMER BALANCES
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'balances'): ?>

    <?php
    $total_bal    = array_sum(array_column($balance_customers, 'outstanding_balance'));
    $total_limit  = array_sum(array_column($balance_customers, 'credit_limit'));
    $clear_count  = count(array_filter($balance_customers, fn($c) => $c['balance_flag'] === 'clear'));
    ?>

    <div class="acm-kpi-grid">
        <div class="acm-kpi">
            <div class="acm-kpi-value">₱<?php echo number_format($total_bal, 2); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-balance-scale"></i> Total Outstanding</div>
        </div>
        <div class="acm-kpi danger">
            <div class="acm-kpi-value"><?php echo number_format($overdue_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-exclamation-circle"></i> Overdue / At Limit</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value"><?php echo number_format(count($balance_customers) - $overdue_count - $clear_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-clock"></i> Has Balance</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value"><?php echo number_format($clear_count); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-check-circle"></i> Clear / No Balance</div>
        </div>
    </div>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-wallet"></i> Customer Balances</h2>
            <span style="font-size:12px;color:#666;">Sorted by highest outstanding balance</span>
        </div>
        <div class="acm-table-wrap">
            <?php if (empty($balance_customers)): ?>
                <div style="padding:40px;text-align:center;color:#999;">No customer balance data found.</div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Outstanding Balance</th>
                        <th>Credit Limit</th>
                        <th>Utilisation</th>
                        <th>Flag</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($balance_customers as $i => $c):
                    $bal   = (float)$c['outstanding_balance'];
                    $limit = (float)$c['credit_limit'];
                    $util  = ($limit > 0) ? min(100, round($bal / $limit * 100)) : 0;
                    $bar_class = $util >= 100 ? 'over' : ($util >= 80 ? 'warn' : '');
                    $flag_class = match($c['balance_flag']) {
                        'overdue'     => 'badge-overdue',
                        'has_balance' => 'badge-balance',
                        default       => 'badge-clear',
                    };
                    $flag_label = match($c['balance_flag']) {
                        'overdue'     => 'Overdue',
                        'has_balance' => 'Has Balance',
                        default       => 'Clear',
                    };
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td style="color:#666;"><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:<?php echo $bal > 0 ? '#dc3545' : '#28a745'; ?>;">
                        ₱<?php echo number_format($bal, 2); ?>
                    </td>
                    <td>₱<?php echo number_format($limit, 2); ?></td>
                    <td style="min-width:120px;">
                        <?php if ($limit > 0): ?>
                            <div style="font-size:11px;color:#666;margin-bottom:2px;"><?php echo $util; ?>%</div>
                            <div class="credit-bar">
                                <div class="credit-bar-fill <?php echo $bar_class; ?>" style="width:<?php echo $util; ?>%;"></div>
                            </div>
                        <?php else: ?>
                            <span style="color:#bbb;font-size:12px;">No limit set</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge-status <?php echo $flag_class; ?>"><?php echo $flag_label; ?></span></td>
                    <td><span class="badge-status <?php echo strtolower($c['status']) === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td>
                        <button class="btn-acm btn-acm-outline btn-acm-sm"
                                onclick="openCreditModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', <?php echo $limit; ?>)">
                            <i class="fas fa-sliders-h"></i> Adjust Limit
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end balances */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 3: ACCOUNTS RECEIVABLE
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'receivable'): ?>

    <div class="acm-kpi-grid">
        <div class="acm-kpi danger">
            <div class="acm-kpi-value">₱<?php echo number_format($total_ar, 2); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-file-invoice-dollar"></i> Total Receivables</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value">₱<?php echo number_format($collected, 2); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-hand-holding-usd"></i> Total Collected</div>
        </div>
        <div class="acm-kpi info">
            <div class="acm-kpi-value"><?php echo count($ar_rows); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-users"></i> Customers with Balance</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value">
                <?php
                $net = $total_ar - $collected;
                echo '₱' . number_format(max(0, $net), 2);
                ?>
            </div>
            <div class="acm-kpi-label"><i class="fas fa-exclamation-triangle"></i> Net Uncollected</div>
        </div>
    </div>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-file-invoice-dollar"></i> Accounts Receivable</h2>
            <span style="font-size:12px;color:#666;">Customers with outstanding credit balances</span>
        </div>
        <div class="acm-table-wrap">
            <?php if (empty($ar_rows)): ?>
                <div style="padding:40px;text-align:center;color:#28a745;">
                    <i class="fas fa-check-circle" style="font-size:40px;margin-bottom:12px;display:block;"></i>
                    No outstanding accounts receivable. All balances are cleared.
                </div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Outstanding Balance</th>
                        <th>Credit Limit</th>
                        <th>Total Paid</th>
                        <th>% Collected</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ar_rows as $i => $c):
                    $bal   = (float)$c['outstanding_balance'];
                    $paid  = (float)($c['total_paid'] ?? 0);
                    $limit = (float)$c['credit_limit'];
                    $total_exposure = $bal + $paid;
                    $pct   = ($total_exposure > 0) ? min(100, round($paid / $total_exposure * 100)) : 0;
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td style="color:#666;"><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td style="font-weight:700;color:#dc3545;">₱<?php echo number_format($bal, 2); ?></td>
                    <td>₱<?php echo number_format($limit, 2); ?></td>
                    <td style="color:#28a745;font-weight:600;">₱<?php echo number_format($paid, 2); ?></td>
                    <td style="min-width:120px;">
                        <div style="font-size:11px;color:#666;margin-bottom:2px;"><?php echo $pct; ?>% collected</div>
                        <div class="credit-bar">
                            <div class="credit-bar-fill" style="width:<?php echo $pct; ?>%;background:#28a745;"></div>
                        </div>
                    </td>
                    <td><span class="badge-status <?php echo strtolower($c['status']) === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td>
                        <a href="?section=history&cid=<?php echo $c['id']; ?>"
                           class="btn-acm btn-acm-primary btn-acm-sm">
                            <i class="fas fa-history"></i> History
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8f9fa;font-weight:700;">
                        <td colspan="3" style="text-align:right;padding:10px 14px;font-size:13px;">TOTALS</td>
                        <td style="color:#dc3545;">₱<?php echo number_format($total_ar, 2); ?></td>
                        <td>—</td>
                        <td style="color:#28a745;">₱<?php echo number_format($collected, 2); ?></td>
                        <td colspan="3">—</td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end receivable */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 4: CUSTOMER HISTORY
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'history'): ?>

    <div class="acm-card">
        <div class="acm-card-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <a href="admin_customer_management.php?section=list" class="btn-acm btn-acm-outline" style="text-decoration:none;padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <h2 style="margin:0;"><i class="fas fa-history"></i> Customer History</h2>
            </div>
            <form method="get" action="" style="margin:0;">
                <input type="hidden" name="section" value="history">
                <div class="acm-toolbar" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <select name="cid" onchange="this.form.submit()" style="min-width:220px;height:36px;box-sizing:border-box;">
                        <option value="0">— Select a customer —</option>
                        <?php foreach ($hist_customers as $hc): ?>
                            <option value="<?php echo $hc['id']; ?>"
                                    <?php echo $hc['id'] == $hist_customer_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($hist_customer_id > 0): ?>
                        <a href="?section=history" class="btn-acm btn-acm-outline" style="text-decoration:none;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;padding:0 12px;">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <a href="?section=history&export=csv&cid=<?php echo $hist_customer_id; ?>" class="btn-acm" style="background:#28a745;color:#fff;text-decoration:none;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;padding:0 12px;">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <a href="?section=history&export=excel&cid=<?php echo $hist_customer_id; ?>" class="btn-acm" style="background:#1f7a3e;color:#fff;text-decoration:none;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;padding:0 12px;">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <button type="button" onclick="printAdminHistoryPDF()" class="btn-acm" style="background:#dc3545;color:#fff;border:none;cursor:pointer;display:inline-flex;align-items:center;height:36px;box-sizing:border-box;padding:0 12px;">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($hist_customer_id <= 0): ?>
            <div style="padding:40px;text-align:center;color:#999;">
                <i class="fas fa-user-clock" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                Select a customer above to view their full transaction history.
            </div>
        <?php elseif (empty($hist_rows)): ?>
            <div style="padding:40px;text-align:center;color:#999;">
                <i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                No transaction records found for this customer.
            </div>
        <?php else: ?>
        <div class="acm-table-wrap">
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Transaction ID</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hist_rows as $i => $r):
                    $type_colors = [
                        'Merchandise' => '#17a2b8',
                        'Job Order'   => '#764ba2',
                        'Payment'     => '#28a745',
                    ];
                    $tc = $type_colors[$r['type']] ?? '#666';
                ?>
                <tr>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-size:12px;color:#666;white-space:nowrap;">
                        <?php echo $r['txn_date'] ? date('M d, Y H:i', strtotime($r['txn_date'])) : '—'; ?>
                    </td>
                    <td>
                        <span class="badge-status"
                              style="background:<?php echo $tc; ?>1a;color:<?php echo $tc; ?>;border:1px solid <?php echo $tc; ?>44;">
                            <?php echo htmlspecialchars($r['type']); ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;font-size:12px;color:#555;">#<?php echo $r['id']; ?></td>
                    <td style="font-weight:700;color:#333;">₱<?php echo number_format((float)$r['total_amount'], 2); ?></td>
                    <td style="color:#555;"><?php echo htmlspecialchars($r['payment_method'] ?? '—'); ?></td>
                    <td>
                        <?php
                        $st = strtolower($r['status'] ?? '');
                        $st_class = match(true) {
                            in_array($st, ['completed','validated','paid','approved']) => 'badge-active',
                            in_array($st, ['cancelled','rejected','inactive'])         => 'badge-inactive',
                            default                                                    => 'badge-balance',
                        };
                        ?>
                        <span class="badge-status <?php echo $st_class; ?>">
                            <?php echo htmlspecialchars($r['status'] ?? 'N/A'); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#666;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?php echo htmlspecialchars($r['notes'] ?? ''); ?>">
                        <?php echo htmlspecialchars($r['notes'] ?? '—'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 20px;font-size:12px;color:#666;border-top:1px solid #f0f0f0;">
            Showing <?php echo count($hist_rows); ?> most recent records.
        </div>
        <?php endif; ?>
    </div>

    <?php endif; /* end history */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 4: CUSTOMER OVERSIGHT
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'oversight'): ?>

    <?php
    $total_oversight = count($oversight_customers);
    $active_oversight = count(array_filter($oversight_customers, fn($c) => strtolower($c['status']) === 'active'));
    $inactive_oversight = count(array_filter($oversight_customers, fn($c) => strtolower($c['status']) === 'inactive'));
    $archived_oversight = count(array_filter($oversight_customers, fn($c) => strtolower($c['status']) === 'archived'));
    ?>

    <!-- KPI row -->
    <div class="acm-kpi-grid">
        <div class="acm-kpi">
            <div class="acm-kpi-value"><?php echo number_format($total_oversight); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-users"></i> Total Customers</div>
        </div>
        <div class="acm-kpi success">
            <div class="acm-kpi-value"><?php echo number_format($active_oversight); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-user-check"></i> Active</div>
        </div>
        <div class="acm-kpi warning">
            <div class="acm-kpi-value"><?php echo number_format($inactive_oversight); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-user-times"></i> Inactive</div>
        </div>
        <div class="acm-kpi info">
            <div class="acm-kpi-value"><?php echo number_format($archived_oversight); ?></div>
            <div class="acm-kpi-label"><i class="fas fa-archive"></i> Archived</div>
        </div>
    </div>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-tasks"></i> Customer Oversight</h2>
            <p style="font-size:12px;color:#666;margin:0;">
                Manage customer records, assign/re-map to stations, delete/archive inactive customers
            </p>
        </div>

        <div class="acm-table-wrap">
            <?php if (empty($oversight_customers)): ?>
                <div style="padding:40px;text-align:center;color:#999;">
                    <i class="fas fa-users-slash" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                    No customers found for oversight.
                </div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer Name</th>
                        <th>Contact</th>
                        <th>Email</th>
                        <th>Station Assigned</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($oversight_customers as $i => $c):
                    $bal = (float)$c['outstanding_balance'];
                    $is_archived = strtolower($c['status']) === 'archived';
                ?>
                <tr <?php echo $is_archived ? 'style="opacity:0.5;background:#f9f9f9;"' : ''; ?>>
                    <td style="color:#999;"><?php echo $i + 1; ?></td>
                    <td style="font-weight:600;color:#333;">
                        <?php echo htmlspecialchars($c['name']); ?>
                        <?php if ($is_archived): ?>
                            <span class="badge-status badge-inactive" style="margin-left:6px;font-size:9px;">ARCHIVED</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
                    <td style="font-size:12px;color:#666;"><?php echo htmlspecialchars($c['email'] ?? '—'); ?></td>
                    <td>
                        <span style="font-weight:600;color:var(--adm-blue);">
                            <?php echo htmlspecialchars($c['station_name'] ?? 'Unknown'); ?>
                        </span>
                        <span style="font-size:11px;color:#999;margin-left:4px;">(ID: <?php echo $c['station_id']; ?>)</span>
                    </td>
                    <td style="font-weight:700;color:<?php echo $bal > 0 ? '#dc3545' : '#28a745'; ?>;">
                        ₱<?php echo number_format($bal, 2); ?>
                    </td>
                    <td>
                        <span class="badge-status <?php echo match(strtolower($c['status'])) {
                            'active' => 'badge-active',
                            'archived' => 'badge-inactive',
                            default => 'badge-balance'
                        }; ?>">
                            <?php echo ucfirst(htmlspecialchars($c['status'] ?? 'unknown')); ?>
                        </span>
                    </td>
                    <td style="color:#666;font-size:12px;white-space:nowrap;">
                        <?php echo date('M d, Y', strtotime($c['created_at'])); ?>
                    </td>
                    <td>
                        <?php if (!$is_archived): ?>
                        <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end;">
                            <button class="action-btn btn-edit"
                                    onclick="openReassignModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', <?php echo $c['station_id']; ?>)"
                                    title="Re-assign to another station">
                                <i class="fas fa-exchange-alt"></i> Re-assign
                            </button>
                            <button class="action-btn btn-danger"
                                    onclick="archiveCustomer(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')"
                                    title="Archive this customer">
                                <i class="fas fa-archive"></i> Archive
                            </button>
                            <a href="?section=history&cid=<?php echo $c['id']; ?>"
                               class="action-btn btn-view" title="View History">
                                <i class="fas fa-history"></i> History
                            </a>
                        </div>
                        <?php else: ?>
                            <span style="font-size:12px;color:#999;font-style:italic;">Archived</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end oversight */ ?>


    <!-- ══════════════════════════════════════════════════════════
         SECTION 6: AUDIT TRAIL
    ══════════════════════════════════════════════════════════ -->
    <?php if ($section === 'audit'): ?>

    <div class="acm-card">
        <div class="acm-card-head">
            <h2><i class="fas fa-clipboard-list"></i> Customer Audit Trail</h2>
            <span style="font-size:12px;color:#666;">All staff &amp; manager actions on customer records</span>
        </div>

        <!-- Filter bar -->
        <div style="padding:14px 20px;border-bottom:1px solid var(--adm-border);background:#f9fafb;">
            <form method="get" action="" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="section" value="audit">
                <div>
                    <label style="font-size:11px;font-weight:700;color:#555;text-transform:uppercase;display:block;margin-bottom:4px;">Search</label>
                    <input type="text" name="aq" value="<?php echo htmlspecialchars($audit_search); ?>"
                           placeholder="Search details / actor…"
                           style="padding:7px 11px;border:1px solid var(--adm-border);border-radius:6px;font-size:13px;min-width:220px;">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#555;text-transform:uppercase;display:block;margin-bottom:4px;">From</label>
                    <input type="date" name="adf" value="<?php echo htmlspecialchars($audit_date_from); ?>"
                           style="padding:7px 11px;border:1px solid var(--adm-border);border-radius:6px;font-size:13px;">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;color:#555;text-transform:uppercase;display:block;margin-bottom:4px;">To</label>
                    <input type="date" name="adt" value="<?php echo htmlspecialchars($audit_date_to); ?>"
                           style="padding:7px 11px;border:1px solid var(--adm-border);border-radius:6px;font-size:13px;">
                </div>
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <button type="submit" class="btn-acm btn-acm-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <?php if ($audit_search || $audit_date_from || $audit_date_to): ?>
                    <a href="?section=audit" class="btn-acm btn-acm-outline">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="acm-table-wrap">
            <?php if (empty($audit_rows)): ?>
                <div style="padding:48px;text-align:center;color:#999;">
                    <i class="fas fa-clipboard" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3;"></i>
                    No customer audit logs found.
                </div>
            <?php else: ?>
            <table class="acm-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>Action</th>
                        <th>Actor</th>
                        <th>Role</th>
                        <th>Station</th>
                        <th style="min-width:320px;">Details</th>
                        <th>Entity</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($audit_rows as $i => $al):
                    $action_lc = strtolower($al['action_type'] ?? '');
                    $act_color = match(true) {
                        in_array($action_lc, ['create','add','insert'])  => ['#d1fae5','#065f46'],
                        in_array($action_lc, ['update','edit','adjust']) => ['#dbeafe','#1e40af'],
                        in_array($action_lc, ['delete','archive'])       => ['#fee2e2','#991b1b'],
                        default                                          => ['#f3f4f6','#374151'],
                    };
                    $log_lc = strtolower($al['log_type'] ?? '');
                    $log_color = match($log_lc) {
                        'success'     => '#28a745',
                        'error','fail'=> '#dc3545',
                        default       => '#6c757d',
                    };
                ?>
                <tr>
                    <td style="color:#999;font-size:12px;"><?php echo $i + 1; ?></td>
                    <td style="white-space:nowrap;font-size:12px;color:#555;">
                        <?php echo $al['created_at'] ? date('M d, Y', strtotime($al['created_at'])) : '—'; ?>
                        <span style="display:block;color:#9ca3af;font-size:11px;">
                            <?php echo $al['created_at'] ? date('h:i A', strtotime($al['created_at'])) : ''; ?>
                        </span>
                    </td>
                    <td>
                        <span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;
                                     background:<?php echo $act_color[0]; ?>;color:<?php echo $act_color[1]; ?>;">
                            <?php echo htmlspecialchars($al['action_type'] ?? 'N/A'); ?>
                        </span>
                    </td>
                    <td style="font-weight:600;color:#333;font-size:13px;">
                        <?php echo htmlspecialchars($al['actor'] ?? '—'); ?>
                    </td>
                    <td style="font-size:12px;color:#6c757d;text-transform:capitalize;">
                        <?php echo htmlspecialchars($al['actor_role'] ?? '—'); ?>
                    </td>
                    <td style="font-size:12px;color:var(--adm-blue);font-weight:600;">
                        <?php echo htmlspecialchars($al['station_name'] ?? 'System'); ?>
                    </td>
                    <td style="font-size:12px;color:#374151;max-width:320px;word-break:break-word;">
                        <?php echo htmlspecialchars($al['details'] ?? '—'); ?>
                    </td>
                    <td style="font-size:12px;color:#6c757d;">
                        <?php if ($al['entity_id']): ?>
                        <span style="font-family:monospace;">
                            <?php echo htmlspecialchars($al['entity_type'] ?? ''); ?> #<?php echo (int)$al['entity_id']; ?>
                        </span>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td>
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo $log_color; ?>;margin-right:4px;"></span>
                        <span style="font-size:11px;font-weight:600;color:<?php echo $log_color; ?>;text-transform:capitalize;">
                            <?php echo htmlspecialchars($al['log_type'] ?? 'N/A'); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="padding:10px 18px;font-size:12px;color:#666;border-top:1px solid #f0f0f0;">
                Showing <?php echo count($audit_rows); ?> most recent records (limit 300).
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; /* end audit */ ?>

</div><!-- end wrapper -->


<!-- ── Credit Limit Modal ─────────────────────────────────── -->
<div class="acm-modal-overlay" id="creditModal">
    <div class="acm-modal">
        <h3><i class="fas fa-sliders-h" style="margin-right:8px;"></i>Adjust Credit Limit</h3>
        <div id="creditModalName" style="font-size:13px;color:#666;margin-bottom:16px;"></div>
        <input type="hidden" id="creditCustomerId">
        <label>New Credit Limit (₱)</label>
        <input type="number" id="creditLimitInput" min="0" step="0.01" placeholder="e.g. 5000.00">
        <label>Note / Reason (optional)</label>
        <textarea id="creditNoteInput" rows="2" placeholder="Reason for adjustment…" style="resize:vertical;"></textarea>
        <div class="acm-modal-actions">
            <button class="btn-acm btn-acm-outline" onclick="closeCreditModal()">Cancel</button>
            <button class="btn-acm btn-acm-primary" onclick="saveCreditLimit()">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- ── Re-assign Station Modal ────────────────────────────── -->
<div class="acm-modal-overlay" id="reassignModal">
    <div class="acm-modal">
        <h3><i class="fas fa-exchange-alt" style="margin-right:8px;"></i>Re-assign Customer to Station</h3>
        <div id="reassignModalName" style="font-size:13px;color:#666;margin-bottom:16px;"></div>
        <input type="hidden" id="reassignCustomerId">
        <input type="hidden" id="reassignCurrentStation">
        <label>Select New Station</label>
        <select id="reassignStationSelect">
            <option value="">— Choose a station —</option>
            <?php foreach ($all_stations as $st): ?>
                <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="acm-modal-actions">
            <button class="btn-acm btn-acm-outline" onclick="closeReassignModal()">Cancel</button>
            <button class="btn-acm btn-acm-primary" onclick="saveReassignment()">
                <i class="fas fa-check"></i> Re-assign
            </button>
        </div>
    </div>
</div>


<script>
// ── Credit limit modal ────────────────────────────────────
function openCreditModal(id, name, currentLimit) {
    document.getElementById('creditCustomerId').value  = id;
    document.getElementById('creditModalName').textContent = 'Customer: ' + name;
    document.getElementById('creditLimitInput').value  = currentLimit;
    document.getElementById('creditNoteInput').value   = '';
    document.getElementById('creditModal').classList.add('open');
}
function closeCreditModal() {
    document.getElementById('creditModal').classList.remove('open');
}

function saveCreditLimit() {
    const id    = document.getElementById('creditCustomerId').value;
    const limit = document.getElementById('creditLimitInput').value;
    const note  = document.getElementById('creditNoteInput').value;

    const fd = new FormData();
    fd.append('action',      'adjust_credit_limit');
    fd.append('customer_id', id);
    fd.append('credit_limit', limit);
    fd.append('note',         note);

    fetch(window.location.pathname + '?section=<?php echo $section; ?>', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeCreditModal();
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'Could not update credit limit.'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ── Toggle customer status ────────────────────────────────
function toggleStatus(id, newStatus) {
    const label = newStatus === 'active' ? 'Reactivate' : 'Deactivate';
    if (!confirm(label + ' this customer?')) return;

    const fd = new FormData();
    fd.append('action',      'toggle_status');
    fd.append('customer_id', id);
    fd.append('status',      newStatus);

    fetch(window.location.pathname + '?section=master', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + (d.error || 'Could not update status.'));
    })
    .catch(() => alert('Network error. Please try again.'));
}

// Close modal when clicking outside
document.getElementById('creditModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreditModal();
});

// ── Re-assign Station modal ───────────────────────────────
function openReassignModal(id, name, currentStationId) {
    document.getElementById('reassignCustomerId').value = id;
    document.getElementById('reassignCurrentStation').value = currentStationId;
    document.getElementById('reassignModalName').textContent = 'Customer: ' + name;
    document.getElementById('reassignStationSelect').value = '';
    document.getElementById('reassignModal').classList.add('open');
}
function closeReassignModal() {
    document.getElementById('reassignModal').classList.remove('open');
}

function saveReassignment() {
    const id = document.getElementById('reassignCustomerId').value;
    const newStationId = document.getElementById('reassignStationSelect').value;
    const currentStationId = document.getElementById('reassignCurrentStation').value;

    if (!newStationId || newStationId === '') {
        alert('Please select a station to re-assign this customer.');
        return;
    }

    if (newStationId === currentStationId) {
        alert('Customer is already assigned to this station.');
        return;
    }

    if (!confirm('Re-assign this customer to the selected station?')) return;

    const fd = new FormData();
    fd.append('action', 'reassign_station');
    fd.append('customer_id', id);
    fd.append('new_station_id', newStationId);

    fetch(window.location.pathname + '?section=oversight', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeReassignModal();
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'Could not re-assign customer.'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ── Archive customer ──────────────────────────────────────
function archiveCustomer(id, name) {
    if (!confirm(`Archive customer "${name}"?\n\nThis will mark the customer as archived and they will no longer appear in active listings.`)) return;

    const fd = new FormData();
    fd.append('action', 'archive_customer');
    fd.append('customer_id', id);

    fetch(window.location.pathname + '?section=oversight', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'Could not archive customer.'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// Close reassign modal when clicking outside
document.getElementById('reassignModal').addEventListener('click', function(e) {
    if (e.target === this) closeReassignModal();
});

function printAdminListPDF() {
    const kpiEl = document.querySelector('.acm-kpi-grid');
    const tableEl = document.querySelector('.acm-table');
    if (!tableEl) { alert('No table found to print.'); return; }
    
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html>
<html><head>
<title>Admin - Customer List</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; color: #333; }
  h2 { color: #002f6c; margin-bottom: 4px; }
  .meta { color: #666; font-size: 11px; margin-bottom: 16px; }
  .kpis { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
  .kpi { flex: 1; min-width: 120px; border: 1px solid #ddd; border-top: 4px solid #002f6c; padding: 8px 12px; border-radius: 4px; background: #fcfcfc; }
  .kpi-val { font-size: 18px; font-weight: 800; color: #002f6c; }
  .kpi-lbl { font-size: 10px; text-transform: uppercase; color: #777; margin-top: 4px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #002f6c; color: #fff; padding: 8px; text-align: left; }
  td { padding: 7px 8px; border-bottom: 1px solid #eee; }
  .badge-status { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
  .badge-active { background: #e6f4ea; color: #137333; }
  .badge-inactive { background: #fce8e6; color: #c5221f; }
  @media print { body { margin: 0; } }
</style>
</head><body>
<h2>Customer List Directory</h2>
<div class="meta">Printed on: \${new Date().toLocaleString()}</div>
<div class="kpis">
  \${kpiEl ? kpiEl.innerHTML.replace(/acm-kpi-value/g, 'kpi-val').replace(/acm-kpi-label/g, 'kpi-lbl').replace(/acm-kpi/g, 'kpi') : ''}
</div>
<table style="width:100%;border-collapse:collapse;font-size:11px;">\${tableEl.innerHTML}</table>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
}

function printAdminHistoryPDF() {
    const tableEl = document.querySelector('.acm-table');
    if (!tableEl) { alert('No history found to print.'); return; }
    
    const custSel = document.querySelector('select[name="cid"]');
    const custName = custSel ? custSel.options[custSel.selectedIndex].text : 'Select a customer';
    
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html>
<html><head>
<title>Admin - Customer History</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; color: #333; }
  h2 { color: #002f6c; margin-bottom: 4px; }
  .meta { color: #666; font-size: 11px; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #002f6c; color: #fff; padding: 8px; text-align: left; }
  td { padding: 7px 8px; border-bottom: 1px solid #eee; }
  @media print { body { margin: 0; } }
</style>
</head><body>
<h2>Customer Transaction History</h2>
<div class="meta">Customer: <strong>\${custName}</strong> &nbsp;|&nbsp; Printed: \${new Date().toLocaleString()}</div>
<table style="width:100%;border-collapse:collapse;font-size:11px;">\${tableEl.innerHTML}</table>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
}
</script>
