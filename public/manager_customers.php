<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$valid_sections = ['records','balances','validation','transactions','history','add'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'records';

$page_id = match($section) {
    'balances'   => 'mgr_cust_balances',
    'history'    => 'mgr_cust_history',
    'add'        => 'mgr_cust_add',
    'validation' => 'mgr_cust_list',
    default      => 'mgr_cust_list',
};

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager','admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// ── Ensure extra columns exist ────────────────────────────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'contact_number' => "VARCHAR(50) NULL",
        'address'        => "TEXT NULL",
        'id_number'      => "VARCHAR(100) NULL",
        'id_type'        => "VARCHAR(100) NULL",
        'id_image'       => "VARCHAR(255) NULL",
        'cr_image'       => "VARCHAR(255) NULL",
        'credit_limit'   => "DECIMAL(12,2) DEFAULT 0.00",
        'balance'        => "DECIMAL(12,2) DEFAULT 0.00",
        'suki_status'    => "VARCHAR(50) DEFAULT 'regular'",
        'payment_terms'  => "VARCHAR(50) DEFAULT 'cash'",
        'status'         => "VARCHAR(20) DEFAULT 'active'",
        'mgr_status'     => "VARCHAR(20) DEFAULT 'pending'",
        'mgr_notes'      => "TEXT NULL",
        'mgr_reviewed_by'=> "INT NULL",
        'mgr_reviewed_at'=> "DATETIME NULL",
    ] as $col => $def) {
        if (!in_array($col, $cols))
            $pdo->exec("ALTER TABLE customers ADD COLUMN $col $def");
    }
} catch (Exception $e) {}

// ── Ensure update-requests table ──────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_update_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        station_id INT NOT NULL,
        requested_by INT NOT NULL,
        field_name VARCHAR(100) NOT NULL,
        old_value TEXT,
        new_value TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        mgr_notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cust(customer_id), INDEX idx_stn(station_id), INDEX idx_st(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Government ID types ───────────────────────────────────────────────────────
$gov_id_types = [
    "Driver's License",
    "Government ID",
    "Passport",
    "Postal ID",
    "Voter's ID",
    "PhilSys ID",
    "PRC ID",
    "Other",
];

// ── POST handlers ─────────────────────────────────────────────────────────────
$flash_ok = $flash_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // ── Encode new customer (manager) ─────────────────────────────────────────
    if ($act === 'encode_customer') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name  = trim($_POST['last_name']  ?? '');
            $name       = trim($first_name . ' ' . $last_name);
        }
        $contact     = trim($_POST['contact'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $id_type     = trim($_POST['id_type'] ?? '');
        $credit      = (float)($_POST['credit_limit'] ?? 0);
        $suki_status = trim($_POST['suki_status'] ?? 'regular');
        $payment_terms = trim($_POST['payment_terms'] ?? 'cash');
        $cust_status = in_array($_POST['cust_status'] ?? '', ['active','inactive','locked']) ? trim($_POST['cust_status']) : 'active';

        // Handle ID image upload
        $id_image_path = null;
        if (!empty($_FILES['id_image']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/customer_ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','webp'])) {
                $fname = 'id_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['id_image']['tmp_name'], $upload_dir . $fname))
                    $id_image_path = 'uploads/customer_ids/' . $fname;
            }
        }
        // Handle CR image upload
        $cr_image_path = null;
        if (!empty($_FILES['cr_image']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/customer_ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['cr_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','webp'])) {
                $fname = 'cr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['cr_image']['tmp_name'], $upload_dir . $fname))
                    $cr_image_path = 'uploads/customer_ids/' . $fname;
            }
        }

        if (!$name) {
            $flash_err = 'Customer name is required.';
        } else {
            try {
                $ins_cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
                $col_list = ['name', 'station_id', 'status', 'mgr_status', 'created_at'];
                $val_list = [$name, $station_id, $cust_status, 'approved', date('Y-m-d H:i:s')];
                if (in_array('contact_number', $ins_cols)) { $col_list[] = 'contact_number'; $val_list[] = $contact; }
                if (in_array('address',        $ins_cols)) { $col_list[] = 'address';        $val_list[] = $address; }
                if (in_array('id_type',        $ins_cols)) { $col_list[] = 'id_type';        $val_list[] = $id_type; }
                if (in_array('id_image',       $ins_cols)) { $col_list[] = 'id_image';       $val_list[] = $id_image_path; }
                if (in_array('cr_image',       $ins_cols)) { $col_list[] = 'cr_image';       $val_list[] = $cr_image_path; }
                if (in_array('credit_limit',   $ins_cols)) { $col_list[] = 'credit_limit';   $val_list[] = $credit; }
                if (in_array('suki_status',    $ins_cols)) { $col_list[] = 'suki_status';    $val_list[] = $suki_status; }
                if (in_array('payment_terms',  $ins_cols)) { $col_list[] = 'payment_terms';  $val_list[] = $payment_terms; }
                if (in_array('balance',        $ins_cols)) { $col_list[] = 'balance';        $val_list[] = 0; }
                $placeholders = implode(',', array_fill(0, count($col_list), '?'));
                $pdo->prepare("INSERT INTO customers (" . implode(',', $col_list) . ") VALUES ($placeholders)")
                    ->execute($val_list);
                $flash_ok = "Customer \"" . htmlspecialchars($name) . "\" added successfully.";
                // ── Audit log ──
                $new_cid = (int)$pdo->lastInsertId();
                write_audit_log($pdo, 'Create',
                    "New customer encoded: {$name}" . ($id_type ? " | ID Type: {$id_type}" : '') . " | Credit Limit: ₱" . number_format($credit, 2) . " | Suki: {$suki_status} | Terms: {$payment_terms}",
                    'customers', $new_cid, 'transaction');
                header("Location: manager_customers.php?section=records&added=1");
                exit;
            } catch (Exception $e) {
                $flash_err = 'Error saving customer: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'update_customer') {
        $cid        = (int)($_POST['customer_id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $name       = trim($first_name . ' ' . $last_name);
        $contact     = trim($_POST['contact'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $id_type     = trim($_POST['id_type'] ?? '');
        $credit      = (float)($_POST['credit_limit'] ?? 0);
        $suki_status = trim($_POST['suki_status'] ?? 'regular');
        $payment_terms = trim($_POST['payment_terms'] ?? 'cash');

        // Handle ID image upload
        $id_image_path = null;
        if (!empty($_FILES['id_image']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/customer_ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','webp'])) {
                $fname = 'id_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['id_image']['tmp_name'], $upload_dir . $fname))
                    $id_image_path = 'uploads/customer_ids/' . $fname;
            }
        }
        // Handle CR image upload
        $cr_image_path = null;
        if (!empty($_FILES['cr_image']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/customer_ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['cr_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','webp'])) {
                $fname = 'cr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['cr_image']['tmp_name'], $upload_dir . $fname))
                    $cr_image_path = 'uploads/customer_ids/' . $fname;
            }
        }

        if (!$cid || !$name) {
            $flash_err = 'Customer ID and name are required.';
        } else {
            try {
                $upd_cols  = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
                $cust_status = in_array($_POST['cust_status'] ?? '', ['active','inactive','locked']) ? trim($_POST['cust_status']) : 'active';
                $set_parts = ['name=?'];
                $upd_vals  = [$name];
                if (in_array('contact_number', $upd_cols)) { $set_parts[] = 'contact_number=?'; $upd_vals[] = $contact; }
                if (in_array('address',        $upd_cols)) { $set_parts[] = 'address=?';        $upd_vals[] = $address; }
                if (in_array('id_type',        $upd_cols)) { $set_parts[] = 'id_type=?';        $upd_vals[] = $id_type; }
                if (in_array('credit_limit',   $upd_cols)) { $set_parts[] = 'credit_limit=?';   $upd_vals[] = $credit; }
                if (in_array('suki_status',    $upd_cols)) { $set_parts[] = 'suki_status=?';    $upd_vals[] = $suki_status; }
                if (in_array('payment_terms',  $upd_cols)) { $set_parts[] = 'payment_terms=?';  $upd_vals[] = $payment_terms; }
                if (in_array('status',         $upd_cols)) { $set_parts[] = 'status=?';          $upd_vals[] = $cust_status; }
                if ($id_image_path && in_array('id_image', $upd_cols)) { $set_parts[] = 'id_image=?'; $upd_vals[] = $id_image_path; }
                if ($cr_image_path && in_array('cr_image', $upd_cols)) { $set_parts[] = 'cr_image=?'; $upd_vals[] = $cr_image_path; }
                $upd_vals[] = $cid;
                $upd_vals[] = $station_id;
                $pdo->prepare("UPDATE customers SET " . implode(',', $set_parts) . " WHERE id=? AND station_id=?")
                    ->execute($upd_vals);
                $flash_ok = "Customer updated successfully.";
                // ── Audit log ──
                write_audit_log($pdo, 'Update',
                    "Customer updated: {$name} (ID #{$cid})" . ($id_type ? " | ID Type: {$id_type}" : '') . " | Credit Limit: ₱" . number_format($credit, 2) . " | Suki: {$suki_status} | Terms: {$payment_terms}",
                    'customers', $cid, 'transaction');
                // Redirect to avoid re-POST on refresh
                header("Location: manager_customers.php?section=records&customer_id={$cid}&updated=1");
                exit;
            } catch (Exception $e) {
                $flash_err = 'Error updating customer: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'validate_customer') {
        $cid  = (int)($_POST['customer_id'] ?? 0);
        $st   = in_array($_POST['status'] ?? '', ['approved','rejected']) ? $_POST['status'] : '';
        $note = trim($_POST['notes'] ?? '');
        if ($cid && $st) {
            try {
                $pdo->prepare("UPDATE customers SET mgr_status=?,mgr_notes=?,mgr_reviewed_by=?,mgr_reviewed_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$st,$note,$me['id'],$cid,$station_id]);
                $flash_ok = "Customer #$cid marked as <strong>$st</strong>.";
            } catch (Exception $e) { $flash_err = $e->getMessage(); }
        }
    }

    if ($act === 'review_update') {
        $rid  = (int)($_POST['request_id'] ?? 0);
        $st   = in_array($_POST['status'] ?? '', ['approved','rejected']) ? $_POST['status'] : '';
        $note = trim($_POST['notes'] ?? '');
        if ($rid && $st) {
            try {
                $pdo->prepare("UPDATE customer_update_requests SET status=?,mgr_notes=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$st,$note,$me['id'],$rid,$station_id]);
                if ($st === 'approved') {
                    $r = $pdo->prepare("SELECT * FROM customer_update_requests WHERE id=?");
                    $r->execute([$rid]);
                    $req = $r->fetch(PDO::FETCH_ASSOC);
                    if ($req && in_array($req['field_name'],['name','contact_number','id_number','credit_limit','email','address'])) {
                        $pdo->prepare("UPDATE customers SET {$req['field_name']}=? WHERE id=? AND station_id=?")
                            ->execute([$req['new_value'],$req['customer_id'],$station_id]);
                    }
                }
                $flash_ok = "Update request #$rid marked as <strong>$st</strong>.";
            } catch (Exception $e) { $flash_err = $e->getMessage(); }
        }
    }
}

// ── Detect balance column ─────────────────────────────────────────────────────
try { $avail = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $avail=[]; }
$bal_col = in_array('credit_balance',$avail) ? 'credit_balance' : (in_array('balance',$avail) ? 'balance' : '0');

// ── AJAX Payment Validation Handler ───────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'validate_payment' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');
    $force = !empty($_POST['force_overpayment']);
    
    // Validation
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Payment amount must be greater than 0.']);
        exit;
    }
    if (strlen($reference) < 3) {
        echo json_encode(['success' => false, 'error' => 'Reference must be at least 3 characters.']);
        exit;
    }
    
    try {
        // Fetch customer
        $stmt = $pdo->prepare("SELECT id, name, COALESCE($bal_col, 0) AS outstanding FROM customers WHERE id = ? AND station_id = ?");
        $stmt->execute([$customer_id, $station_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'error' => 'Customer not found.']);
            exit;
        }
        
        $outstanding = (float)$customer['outstanding'];
        
        // Check overpayment
        if (!$force && $amount > $outstanding) {
            echo json_encode([
                'success' => false,
                'overpayment' => true,
                'excess' => $amount - $outstanding
            ]);
            exit;
        }
        
        // Process payment in transaction
        $pdo->beginTransaction();
        
        $new_balance = max(0, $outstanding - $amount);
        $update_stmt = $pdo->prepare("UPDATE customers SET $bal_col = ?, balance = ? WHERE id = ? AND station_id = ?");
        $update_stmt->execute([$new_balance, $new_balance, $customer_id, $station_id]);
        
        // Record in customer_credit_transactions
        $cct_stmt = $pdo->prepare("
            INSERT INTO customer_credit_transactions (
                customer_id, transaction_id, transaction_type, amount, 
                running_balance, description, station_id, created_by, created_at
            ) VALUES (?, CONCAT('PAY-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', ?), 'Payment', ?, ?, ?, ?, ?, NOW())
        ");
        $cct_stmt->execute([
            $customer_id,
            $reference,
            $amount,
            $new_balance,
            "Payment received - Ref: " . $reference,
            $station_id,
            $me['id']
        ]);
        
        // Audit log
        write_audit_log($pdo, 'Payment Validated',
            "Payment received: ₱" . number_format($amount, 2) . " from " . $customer['name'] . " | Ref: " . $reference . " | New Balance: ₱" . number_format($new_balance, 2),
            'customers', $customer_id, 'transaction');
        
        $pdo->commit();
        
        // Calculate new utilization
        $credit_limit = (float)($pdo->query("SELECT COALESCE(credit_limit, 0) FROM customers WHERE id = $customer_id")->fetchColumn());
        $new_utilization = $credit_limit > 0 ? round(($new_balance / $credit_limit) * 100, 1) : 0;
        
        echo json_encode([
            'success' => true,
            'new_balance' => $new_balance,
            'new_utilization' => $new_utilization
        ]);
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Database error. No changes were made.']);
        exit;
    }
}

// Detect column details for merchandise_transactions
$mt_cid_col = 'credit_customer_id';
try {
    $mt_check_cols = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('customer_id', $mt_check_cols)) {
        $mt_cid_col = 'customer_id';
    }
} catch(Exception $e) {}

// ── Master Customer List CSV/Excel Export Handler ───────────────────────────
if ($section === 'records' && isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $is_excel = ($_GET['export'] === 'excel');
    try {
        $avail = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $sel_contact  = in_array('contact_number', $avail) ? 'contact_number' : "'' AS contact_number";
        $sel_address  = in_array('address',        $avail) ? 'address'        : "'' AS address";
        $sel_id_type  = in_array('id_type',        $avail) ? 'id_type'        : "'' AS id_type";
        $sel_credit   = in_array('credit_limit',   $avail) ? 'credit_limit'   : "0.00 AS credit_limit";
        $sel_suki     = in_array('suki_status',    $avail) ? 'suki_status'    : "'regular' AS suki_status";
        $sel_terms    = in_array('payment_terms',  $avail) ? 'payment_terms'  : "'cash' AS payment_terms";
        $sel_balance  = in_array('balance',        $avail) ? 'balance'        : "0.00 AS balance";
        $sel_status   = in_array('status',         $avail) ? 'status'         : "'active' AS status";
        
        $s = $pdo->prepare("SELECT id, name, $sel_contact, $sel_address, $sel_id_type, $sel_credit, $sel_suki, $sel_terms, $sel_balance, $sel_status FROM customers WHERE station_id=? ORDER BY name");
        $s->execute([$station_id]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        
        if (ob_get_level() > 0) ob_end_clean();
        $filename = 'customer_list_' . date('Y-m-d') . ($is_excel ? '.xls' : '.csv');
        if ($is_excel) {
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
        }
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Customer List Master Directory']);
        fputcsv($output, ['Station:', 'Station #' . $station_id]);
        fputcsv($output, ['Export Date:', date('F d, Y h:i A')]);
        fputcsv($output, ['Total Customers:', count($rows)]);
        fputcsv($output, []);
        
        fputcsv($output, ['ID', 'Customer Name', 'Contact Number', 'Address', 'ID Type', 'Suki Status', 'Payment Terms', 'Credit Limit', 'Balance', 'Status']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['contact_number'] ?? '—',
                $row['address'] ?? '—',
                $row['id_type'] ?? '—',
                ucfirst($row['suki_status'] ?? 'regular'),
                ucfirst($row['payment_terms'] ?? 'cash'),
                number_format((float)$row['credit_limit'], 2),
                number_format((float)$row['balance'], 2),
                ucfirst($row['status'] ?? 'active')
            ]);
        }
        fclose($output);
        
        write_audit_log($pdo, 'Export Customer List', "Exported customer list directory to " . strtoupper($_GET['export']), 'customers', 0, 'report');
        exit;
    } catch (Exception $e) {
        $_SESSION['flash_err'] = 'Error exporting customer list: ' . $e->getMessage();
        header('Location: manager_customers.php?section=records');
        exit;
    }
}

// ── Customer History CSV/Excel Export Handler ───────────────────────────────
if ($section === 'history' && isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $is_excel = ($_GET['export'] === 'excel');
    $history_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
    $history_end = $_GET['end_date'] ?? date('Y-m-d');
    $history_customer_filter = (int)($_GET['customer_filter'] ?? 0);
    $date_end_eod = $history_end . ' 23:59:59';
    
    $params = [
        ':station_id' => $station_id,
        ':customer_id' => $history_customer_filter,
        ':date_start' => $history_start,
        ':date_end_eod' => $date_end_eod
    ];
    
    $query = "
        SELECT
            cct.created_at AS txn_date,
            cct.transaction_id AS reference_no,
            CASE 
                WHEN cct.transaction_type = 'Sale' THEN 'Credit Sale'
                WHEN cct.transaction_type = 'Payment' THEN 'Payment'
                ELSE cct.transaction_type 
            END AS txn_type,
            cct.amount AS amount,
            CASE WHEN cct.transaction_type = 'Payment' THEN 'Cash' ELSE '—' END AS payment_method,
            COALESCE(u.full_name, u.name, '—') AS staff_name,
            cct.customer_id,
            COALESCE(c.name, '—') AS customer_name
        FROM customer_credit_transactions cct
        LEFT JOIN users u ON u.id = cct.created_by
        LEFT JOIN customers c ON c.id = cct.customer_id
        WHERE cct.station_id = :station_id
          AND (:customer_id = 0 OR cct.customer_id = :customer_id)
          AND cct.created_at BETWEEN :date_start AND :date_end_eod
        ORDER BY txn_date DESC
    ";
    
    try {
        $export_stmt = $pdo->prepare($query);
        $export_stmt->execute($params);
        $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($export_data)) {
            $_SESSION['flash_err'] = 'No transactions found to export. Please adjust your filters.';
            header('Location: manager_customers.php?section=history&start_date=' . urlencode($history_start) . '&end_date=' . urlencode($history_end) . '&customer_filter=' . $history_customer_filter);
            exit;
        }
        
        $station_name = 'Station #' . $station_id;
        try {
            $stn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $stn_stmt->execute([$station_id]);
            $stn = $stn_stmt->fetchColumn();
            if ($stn) $station_name = $stn;
        } catch (Exception $e) {}
        
        $customer_name_filter = 'All Customers';
        if ($history_customer_filter > 0) {
            try {
                $cust_name_stmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
                $cust_name_stmt->execute([$history_customer_filter]);
                $cn = $cust_name_stmt->fetchColumn();
                if ($cn) $customer_name_filter = $cn;
            } catch (Exception $e) {}
        }
        
        if (ob_get_level() > 0) ob_end_clean();
        
        $filename = 'customer_history_' . date('Y-m-d') . ($is_excel ? '.xls' : '.csv');
        if ($is_excel) {
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
        }
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Customer Transaction History Export']);
        fputcsv($output, ['Station:', $station_name]);
        fputcsv($output, ['Manager:', $me['name'] ?? $me['username'] ?? 'Unknown']);
        fputcsv($output, ['Export Date:', date('F d, Y h:i A')]);
        fputcsv($output, ['Date Range:', date('M d, Y', strtotime($history_start)) . ' to ' . date('M d, Y', strtotime($history_end))]);
        fputcsv($output, ['Customer Filter:', $customer_name_filter]);
        fputcsv($output, ['Total Transactions:', count($export_data)]);
        fputcsv($output, []);
        
        fputcsv($output, ['Date', 'Reference', 'Type', 'Customer', 'Amount', 'Payment Method', 'Recorded By']);
        
        foreach ($export_data as $row) {
            fputcsv($output, [
                date('M d, Y h:i A', strtotime($row['txn_date'])),
                $row['reference_no'],
                $row['txn_type'],
                $row['customer_name'] ?? '—',
                number_format((float)$row['amount'], 2),
                $row['payment_method'] ?? '—',
                $row['staff_name']
            ]);
        }
        
        fclose($output);
        
        write_audit_log($pdo, 'Export Customer History',
            strtoupper($_GET['export']) . " export: {$customer_name_filter} | Date Range: {$history_start} to {$history_end} | " . count($export_data) . " transactions",
            'customers', 0, 'report');
        
        exit;
        
    } catch (Exception $e) {
        $_SESSION['flash_err'] = 'Error generating export: ' . $e->getMessage();
        header('Location: manager_customers.php?section=history');
        exit;
    }
}

// ── Section data ──────────────────────────────────────────────────────────────
$balance_customers = [];
$history_transactions = [];
$history_customers = [];
$history_start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
$history_end = $_GET['end_date'] ?? date('Y-m-d');
$history_customer_filter = (int)($_GET['customer_filter'] ?? 0);

if ($section === 'balances') {
    try {
        $s = $pdo->prepare("SELECT c.id, c.name, COALESCE(c.$bal_col,0) AS balance,
            COALESCE(c.credit_limit,0) AS credit_limit, c.status,
            COALESCE(c.contact_number,'—') AS contact_number,
            (SELECT MAX(COALESCE(mt.transaction_date, mt.created_at))
             FROM merchandise_transactions mt
             WHERE mt.{$mt_cid_col} = c.id) AS last_txn_date
            FROM customers c WHERE c.station_id=? AND (COALESCE(c.credit_limit,0) > 0 OR COALESCE(c.$bal_col,0) > 0)
            ORDER BY balance DESC");
        $s->execute([$station_id]);
        $balance_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}

if ($section === 'history') {
    try {
        // Get customers for dropdown
        $cust_stmt = $pdo->prepare("SELECT id, name FROM customers WHERE station_id = ? ORDER BY name ASC");
        $cust_stmt->execute([$station_id]);
        $history_customers = $cust_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Query from customer_credit_transactions — the canonical ledger
        $date_end_eod = $history_end . ' 23:59:59';
        $params = [
            ':station_id' => $station_id,
            ':customer_id' => $history_customer_filter,
            ':date_start' => $history_start,
            ':date_end_eod' => $date_end_eod
        ];
        
        $query = "
            SELECT
                cct.created_at AS txn_date,
                cct.transaction_id AS reference_no,
                CASE
                    WHEN cct.transaction_type = 'Sale'       THEN 'Credit Sale'
                    WHEN cct.transaction_type = 'Payment'    THEN 'Payment'
                    WHEN cct.transaction_type = 'Adjustment' THEN 'Adjustment'
                    ELSE cct.transaction_type
                END AS txn_type,
                cct.amount AS amount,
                CASE WHEN cct.transaction_type = 'Payment' THEN 'Cash' ELSE 'Credit' END AS payment_method,
                COALESCE(u.full_name, u.name, '—') AS staff_name,
                cct.customer_id,
                cct.running_balance,
                cct.description
            FROM customer_credit_transactions cct
            LEFT JOIN users u ON u.id = cct.created_by
            WHERE cct.station_id = :station_id
              AND (:customer_id = 0 OR cct.customer_id = :customer_id)
              AND cct.created_at BETWEEN :date_start AND :date_end_eod

            ORDER BY txn_date DESC
            LIMIT 500
        ";
        
        $hist_stmt = $pdo->prepare($query);
        $hist_stmt->execute($params);
        $history_transactions = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $hist_total_sales = 0;
        $hist_total_payments = 0;
        $hist_total_count = count($history_transactions);
        foreach ($history_transactions as $txn) {
            if ($txn['txn_type'] === 'Payment') {
                $hist_total_payments += (float)$txn['amount'];
            } else {
                $hist_total_sales += (float)$txn['amount'];
            }
        }
    } catch(Exception $e) {
        $history_transactions = [];
    }
}

$section_meta = [
    'records'    => ['fas fa-users',         'Customer List',           'Review, validate, and edit customer profiles within your station.'],
    'balances'   => ['fas fa-wallet',        'Customer Balances',       'Monitor outstanding balances and credit usage within your station.'],
    'history'    => ['fas fa-history',       'Customer History',        'View and validate transaction history within your station.'],
    'add'        => ['fas fa-user-plus',     'Add New Customer',        'Encode confidential customer information (credit line, suki status, terms).'],
    'validation' => ['fas fa-user-shield',   'Validation & Oversight',  'Manager validation and oversight'],
    'transactions'=>['fas fa-receipt',       'Customer Transactions',    'Customer transaction oversight'],
];
[$sec_ico, $sec_title, $sec_subtitle] = array_merge($section_meta[$section] ?? [], ['fas fa-users', 'Customers', '']);

// ── Data for records section ───────────────────────────────────────────────────
$records_customers = [];
$edit_customer     = null;
if ($section === 'records') {
    try {
        $rc_avail   = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $rc_contact = in_array('contact_number', $rc_avail) ? 'contact_number' : "'' AS contact_number";
        $rc_address = in_array('address',        $rc_avail) ? 'address'        : "'' AS address";
        $rc_id_type = in_array('id_type',        $rc_avail) ? 'id_type'        : "'' AS id_type";
        $rc_id_img  = in_array('id_image',       $rc_avail) ? 'id_image'       : "'' AS id_image";
        $rc_cr_img  = in_array('cr_image',       $rc_avail) ? 'cr_image'       : "'' AS cr_image";
        $rc_balance = in_array('balance',        $rc_avail) ? 'balance'        : "0 AS balance";
        $rc_credit  = in_array('credit_limit',   $rc_avail) ? 'credit_limit'   : "0 AS credit_limit";
        $rc_suki    = in_array('suki_status',    $rc_avail) ? 'suki_status'    : "'regular' AS suki_status";
        $rc_terms   = in_array('payment_terms',  $rc_avail) ? 'payment_terms'  : "'cash' AS payment_terms";
        $rc_status  = in_array('status',         $rc_avail) ? 'status'         : "'active' AS status";
        $s = $pdo->prepare("SELECT id, name, $rc_contact, $rc_address, $rc_id_type, $rc_id_img, $rc_cr_img, $rc_credit, $rc_suki, $rc_terms, $rc_balance, $rc_status FROM customers WHERE station_id=? ORDER BY name");
        $s->execute([$station_id]);
        $records_customers = $s->fetchAll(PDO::FETCH_ASSOC);
        
        $mgr_total = count($records_customers);
        $mgr_active = 0;
        $mgr_locked = 0;
        $mgr_total_balance = 0;
        foreach ($records_customers as $rc) {
            $cstatus = strtolower($rc['status'] ?? 'active');
            if ($cstatus === 'active') {
                $mgr_active++;
            } elseif ($cstatus === 'locked') {
                $mgr_locked++;
            }
            $mgr_total_balance += (float)$rc['balance'];
        }
    } catch (Exception $e) {}

    if (isset($_GET['customer_id'])) {
        $cid_get = (int)$_GET['customer_id'];
        foreach ($records_customers as $rc) {
            if ($rc['id'] === $cid_get) { $edit_customer = $rc; break; }
        }
    }
    // Show success flash
    if (isset($_GET['updated'])) $flash_ok = "Customer updated successfully.";
    if (isset($_GET['added'])) $flash_ok = "Customer added successfully.";
}

// ── Data for validation section ──────────────────────────────────────────────
$pending_new_customers = [];
$pending_update_requests = [];
if ($section === 'validation') {
    try {
        $s1 = $pdo->prepare("SELECT * FROM customers WHERE station_id=? AND mgr_status='pending' ORDER BY created_at DESC");
        $s1->execute([$station_id]);
        $pending_new_customers = $s1->fetchAll(PDO::FETCH_ASSOC);

        $s2 = $pdo->prepare("SELECT r.*, c.name as customer_name, u.name as staff_name FROM customer_update_requests r JOIN customers c ON r.customer_id=c.id JOIN users u ON r.requested_by=u.id WHERE r.station_id=? AND r.status='pending' ORDER BY r.created_at DESC");
        $s2->execute([$station_id]);
        $pending_update_requests = $s2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Data for transactions section ────────────────────────────────────────────
$transactions = [];
$transaction_customer = null;
if ($section === 'transactions' && isset($_GET['customer_id'])) {
    $cid = (int)$_GET['customer_id'];
    try {
        $cstmt = $pdo->prepare("SELECT id, name FROM customers WHERE id=? AND station_id=?");
        $cstmt->execute([$cid, $station_id]);
        $transaction_customer = $cstmt->fetch(PDO::FETCH_ASSOC);

        if ($transaction_customer) {
            $tstmt = $pdo->prepare("
                SELECT t.id, t.transaction_type, t.total_amount, t.status, t.created_at, u.name as staff_name 
                FROM transactions t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.customer_id=? AND t.station_id=? 
                ORDER BY t.created_at DESC
            ");
            $tstmt->execute([$cid, $station_id]);
            $transactions = $tstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* === Clean Manager Design === */
.mgrc-card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e9ecef;margin-bottom:20px;overflow:hidden;max-width:100%;}
.mgrc-head{padding:14px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.mgrc-title{font-size:15px;font-weight:700;color:#002F70;margin:0;display:flex;align-items:center;gap:8px;}
.mgrc-body{padding:16px;overflow-x:hidden;max-width:100%;box-sizing:border-box;}
.mgrc-body form{max-width:100%;box-sizing:border-box;overflow:hidden;}
.mgrc-body form > *{max-width:100%;box-sizing:border-box;}
.mgrc-table{width:100%;border-collapse:collapse;font-size:0.875rem;}
.mgrc-table th{background:#002F70 !important;color:#fff !important;padding:14px 16px;text-align:left;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;border:none !important;}
.mgrc-table td{padding:12px 16px;border-bottom:1px solid #e9ecef;vertical-align:middle;color:#212529;}
.mgrc-table tr:hover td{background:#e3f2fd;}
.mgrc-table tbody tr:last-child td{border-bottom:none;}

/* Plain text badges - NO backgrounds */
.badge-pending{color:#fd7e14 !important;font-weight:700 !important;background:none !important;padding:0 !important;}
.badge-approved{color:#28a745 !important;font-weight:700 !important;background:none !important;padding:0 !important;}
.badge-rejected{color:#dc3545 !important;font-weight:700 !important;background:none !important;padding:0 !important;}
.badge-active{color:#28a745 !important;font-weight:700 !important;background:none !important;padding:0 !important;}
.badge-inactive{color:#6c757d !important;font-weight:700 !important;background:none !important;padding:0 !important;}
.badge-overdue{color:#dc3545 !important;font-weight:700 !important;background:none !important;padding:0 !important;}

/* Locked status badge */
.badge-locked{color:#7c3aed !important;font-weight:700 !important;background:none !important;padding:0 !important;}

.mgrc-btn{padding:6px 12px;border:none;border-radius:6px;font-size:0.75rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:4px;min-width:85px;justify-content:center;}
.mgrc-btn-approve{background:#28a745;color:#fff;} .mgrc-btn-approve:hover{background:#218838;}
.mgrc-btn-reject{background:#dc3545;color:#fff;} .mgrc-btn-reject:hover{background:#c82333;}
.mgrc-btn-view{background:#002F70;color:#fff;text-decoration:none;} .mgrc-btn-view:hover{background:#001a4d;}
.mgrc-empty{text-align:center;padding:40px;color:#9ca3af;}
.mgrc-empty i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4;}
.mgrc-search{width:100%;padding:10px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;margin-bottom:14px;box-sizing:border-box;}
.mgrc-search:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
.mgrc-info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;font-size:12px;color:#1e40af;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.mgrc-bal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;}
.mgrc-bal-card{background:linear-gradient(135deg,#fff 0%,#f8f9fa 100%);border:1px solid #e9ecef;border-radius:10px;padding:18px;transition:all .2s;}
.mgrc-bal-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.12);}
.mgrc-bal-name{font-size:15px;font-weight:700;color:#002F70;margin-bottom:8px;}
.mgrc-bal-amount{font-size:22px;font-weight:700;margin-bottom:6px;}
.mgrc-bal-detail{font-size:12px;color:#6c757d;display:flex;justify-content:space-between;margin-bottom:4px;}
.mgrc-section-head{font-size:14px;font-weight:700;color:#002F70;margin:18px 0 10px;display:flex;align-items:center;gap:6px;border-bottom:2px solid #e9ecef;padding-bottom:8px;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.modal-title{font-size:17px;font-weight:700;color:#002F70;margin-bottom:16px;}
.modal-label{display:block;font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.modal-input{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;margin-bottom:14px;}
.modal-input:focus{border-color:#002F70;outline:none;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:6px;}
.flash-ok{background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#065f46;font-weight:600;}
.flash-err{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#991b1b;font-weight:600;}
.cust-sel{padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:260px;}

/* Prevent horizontal scroll */
body{overflow-x:hidden !important;max-width:100vw !important;}
.mgrc-table-wrap{overflow-x:auto !important;-webkit-overflow-scrolling:touch;}
@media(max-width:640px){.mgrc-bal-grid{grid-template-columns:1fr;}}

/* Tab Navigation - Clean Staff Design */
.mgrc-tab{padding:12px 20px;font-weight:600;color:#6c757d;border-bottom:3px solid transparent;cursor:pointer;font-size:14px;transition:all .15s;display:inline-flex;align-items:center;gap:8px;text-decoration:none;margin-bottom:-2px;background:none;}
.mgrc-tab:hover{color:#002F70;background:#f8f9fa;}
.mgrc-tab.active{color:#002F70;border-bottom-color:#002F70;}
.mgrc-tab i{font-size:15px;}

/* Customer Update/Encode Forms Style */
.upd-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:100%;box-sizing:border-box;}
@media(max-width:768px){.upd-form-grid{grid-template-columns:1fr;gap:10px;}}
.upd-label{display:block;font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;}
.upd-input{width:100%;max-width:100%;padding:8px 11px;border:1px solid #dee2e6;border-radius:5px;font-size:13px;box-sizing:border-box;background:#fff;}
.upd-input:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.upd-input[type="file"]{padding:5px 9px;font-size:12px;}
select.upd-input{padding:8px 11px;}

/* Form sections and containers - prevent overflow & compact spacing */
.mgrc-body form > div{
  margin-bottom:18px !important;
  max-width:100% !important;
  box-sizing:border-box !important;
}
.mgrc-body form > div:last-child{margin-bottom:0 !important;}
.mgrc-body form h3{
  font-size:13px !important;
  margin:0 0 10px !important;
  padding-bottom:6px !important;
}
.mgrc-body form > div[style*="padding"]{
  padding:16px !important;
}
.mgrc-body form div[style*="margin-bottom"]{
  margin-bottom:16px !important;
}

/* Grid containers within forms */
.mgrc-body form div[style*="display:grid"]{
  max-width:100% !important;
  box-sizing:border-box !important;
  gap:12px !important;
}

/* Print Styles for PDF Export */
@media print {
  .mgrc-tab, .mgrc-search, .mgrc-btn, .page-head .header-actions, 
  .flash-ok, .flash-err, nav, .sidebar, footer, .modal-overlay,
  form[method="GET"], button[onclick*="print"] {
    display: none !important;
  }
  .mgrc-card {
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    page-break-inside: avoid;
  }
  .mgrc-table {
    font-size: 10px !important;
  }
  .mgrc-table th {
    background: #f0f0f0 !important;
    color: #000 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  body {
    background: white !important;
  }
  .page-head {
    margin-bottom: 10px;
  }
  .mgrc-head {
    padding: 10px !important;
    page-break-after: avoid;
  }
}

</style>
<div class="page-head">
  <div>
    <h1 class="h1"><i class="<?php echo $sec_ico; ?>"></i> <?php echo $sec_title; ?></h1>
    <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; <?php echo $sec_subtitle; ?></div>
  </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><i class="fas fa-check-circle"></i> <?php echo $flash_ok; ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>


<!-- ===== SECTION: ADD NEW CUSTOMER ===== -->
<?php if ($section === 'add'): ?>
<div class="mgrc-card">
  <div class="mgrc-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h2 class="mgrc-title"><i class="fas fa-user-plus"></i> Add New Customer</h2>
    <a href="manager_customers.php?section=records" class="mgrc-btn" style="background:#6c757d;color:#fff;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
      <i class="fas fa-arrow-left"></i> Back to List
    </a>
  </div>
  <div class="mgrc-body">
    
    <form method="POST" action="manager_customers.php?section=add" enctype="multipart/form-data">
      <input type="hidden" name="action" value="encode_customer">
      
      <!-- Basic Customer Information -->
      <div style="margin-bottom:24px;">
        <h3 style="font-size:14px;font-weight:700;color:#002F70;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid #e9ecef;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-user"></i> Basic Information
        </h3>
        <div class="upd-form-grid">
          <div>
            <label class="upd-label">First Name <span style="color:red">*</span></label>
            <input type="text" name="first_name" class="upd-input" placeholder="First Name" required maxlength="100" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
          </div>
          <div>
            <label class="upd-label">Last Name <span style="color:red">*</span></label>
            <input type="text" name="last_name" class="upd-input" placeholder="Last Name" required maxlength="100" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
          </div>
          <div>
            <label class="upd-label">Contact Number</label>
            <input type="text" name="contact" class="upd-input" placeholder="e.g. 09XX-XXX-XXXX" maxlength="50" value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>">
          </div>
          <div>
            <label class="upd-label">Address</label>
            <input type="text" name="address" class="upd-input" placeholder="Street, Barangay, City" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>">
          </div>
        </div>
      </div>

      <!-- Private Data Section (Manager-Only) -->
      <div style="margin-bottom:24px;padding:20px;background:#fffbeb;border:2px solid #fbbf24;border-radius:10px;">
        <h3 style="font-size:14px;font-weight:700;color:#b45309;margin:0 0 4px;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-shield-alt"></i> Private Data (Manager Only)
        </h3>
        <p style="font-size:12px;color:#92400e;margin:0 0 16px;">Update credit line, suki status, and payment terms.</p>
        
        <div class="upd-form-grid">
          <div>
            <label class="upd-label" style="color:#92400e;">Credit Limit (₱)</label>
            <input type="number" name="credit_limit" class="upd-input" value="<?php echo htmlspecialchars($_POST['credit_limit'] ?? '0'); ?>" min="0" step="0.01" style="border-color:#fbbf24;">
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">Suki Status</label>
            <select name="suki_status" class="upd-input" style="border-color:#fbbf24;">
              <option value="">— Select Status —</option>
              <option value="regular" <?php echo ($_POST['suki_status'] ?? '') === 'regular' ? 'selected' : ''; ?>>Regular Customer</option>
              <option value="suki" <?php echo ($_POST['suki_status'] ?? '') === 'suki' ? 'selected' : ''; ?>>Suki / Loyal Customer</option>
              <option value="vip" <?php echo ($_POST['suki_status'] ?? '') === 'vip' ? 'selected' : ''; ?>>VIP Customer</option>
            </select>
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">Payment Terms</label>
            <select name="payment_terms" class="upd-input" style="border-color:#fbbf24;">
              <option value="cash" <?php echo ($_POST['payment_terms'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>>Cash Only</option>
              <option value="7days" <?php echo ($_POST['payment_terms'] ?? '') === '7days' ? 'selected' : ''; ?>>7 Days Credit</option>
              <option value="15days" <?php echo ($_POST['payment_terms'] ?? '') === '15days' ? 'selected' : ''; ?>>15 Days Credit</option>
              <option value="30days" <?php echo ($_POST['payment_terms'] ?? '') === '30days' ? 'selected' : ''; ?>>30 Days Credit</option>
            </select>
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">Type of ID</label>
            <select name="id_type" class="upd-input" style="border-color:#fbbf24;">
              <option value="">— Select ID Type —</option>
              <?php foreach ($gov_id_types as $idt): ?>
              <option value="<?php echo htmlspecialchars($idt); ?>" <?php echo ($_POST['id_type'] ?? '') === $idt ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($idt); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- ID & CR Upload -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;">
          <div>
            <label class="upd-label" style="color:#92400e;">Upload ID (Front/Copy)</label>
            <input type="file" name="id_image" class="upd-input" accept="image/*,.pdf" style="padding:6px 10px;border-color:#fbbf24;">
            <span style="font-size:11px;color:#92400e;margin-top:3px;display:block;">JPG, PNG, PDF — max 5MB</span>
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">CR / Certificate of Registration</label>
            <input type="file" name="cr_image" class="upd-input" accept="image/*,.pdf" style="padding:6px 10px;border-color:#fbbf24;">
            <span style="font-size:11px;color:#92400e;margin-top:3px;display:block;">For business customers</span>
          </div>
        </div>
      </div>
      
      <!-- Customer Account Status (Manager Only) -->
      <div style="margin-bottom:20px;">
        <h3 style="font-size:14px;font-weight:700;color:#002F70;margin:0 0 12px;padding-bottom:8px;border-bottom:2px solid #e9ecef;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-toggle-on"></i> Account Status
        </h3>
        <div style="max-width:280px;">
          <label class="upd-label">Customer Status <span style="color:red">*</span></label>
          <select name="cust_status" class="upd-input">
            <option value="active"   <?php echo ($_POST['cust_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>✅ Active</option>
            <option value="inactive" <?php echo ($_POST['cust_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>⚫ Inactive</option>
            <option value="locked"   <?php echo ($_POST['cust_status'] ?? '') === 'locked' ? 'selected' : ''; ?>>🔒 Locked — Blocked from transactions</option>
          </select>
          <span style="font-size:11px;color:#6c757d;margin-top:4px;display:block;">Locked customers cannot make new credit or purchase transactions.</span>
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;padding-top:8px;border-top:1px solid #f0f0f0;margin-top:4px;">
        <button type="submit" class="mgrc-btn mgrc-btn-approve" style="padding:11px 24px;font-size:14px;">
          <i class="fas fa-save"></i> Save Customer
        </button>
        <a href="?section=records" class="mgrc-btn" style="background:#6c757d;color:#fff;padding:11px 20px;font-size:14px;text-decoration:none;">
          <i class="fas fa-times"></i> Cancel
        </a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($section === 'records'): ?>

<?php if ($edit_customer): ?>
<!-- Edit form for selected customer -->
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-edit"></i> Editing: <?php echo htmlspecialchars($edit_customer['name']); ?></h2>
    <a href="manager_customers.php?section=records" class="mgrc-btn" style="background:#6c757d;color:#fff;font-size:12px;">
      <i class="fas fa-arrow-left"></i> Back to List
    </a>
  </div>
  <div class="mgrc-body">
    <form method="POST" action="manager_customers.php?section=records" enctype="multipart/form-data" style="max-width:800px;">
      <input type="hidden" name="action" value="update_customer">
      <input type="hidden" name="customer_id" value="<?php echo (int)$edit_customer['id']; ?>">
      
      <?php 
        $name_parts = explode(' ', $edit_customer['name'], 2);
        $fname = $name_parts[0] ?? '';
        $lname = $name_parts[1] ?? '';
      ?>
      
      <!-- Basic Customer Information -->
      <div style="margin-bottom:24px;">
        <h3 style="font-size:14px;font-weight:700;color:#002F70;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid #e9ecef;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-user"></i> Basic Information
        </h3>
        <div class="upd-form-grid">
          <div>
            <label class="upd-label">First Name <span style="color:red">*</span></label>
            <input type="text" name="first_name" class="upd-input" value="<?php echo htmlspecialchars($fname); ?>" required>
          </div>
          <div>
            <label class="upd-label">Last Name <span style="color:red">*</span></label>
            <input type="text" name="last_name" class="upd-input" value="<?php echo htmlspecialchars($lname); ?>" required>
          </div>
          <div>
            <label class="upd-label">Contact Number</label>
            <input type="text" name="contact" class="upd-input" value="<?php echo htmlspecialchars($edit_customer['contact_number'] ?? ''); ?>">
          </div>
          <div>
            <label class="upd-label">Address</label>
            <input type="text" name="address" class="upd-input" value="<?php echo htmlspecialchars($edit_customer['address'] ?? ''); ?>">
          </div>
        </div>
      </div>

      <!-- Private Data Section (Manager-Only) -->
      <div style="margin-bottom:24px;padding:20px;background:#fffbeb;border:2px solid #fbbf24;border-radius:10px;">
        <h3 style="font-size:14px;font-weight:700;color:#b45309;margin:0 0 4px;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-shield-alt"></i> Private Data (Manager Only)
        </h3>
        <p style="font-size:12px;color:#92400e;margin:0 0 16px;">Update credit line, suki status, and payment terms.</p>
        
        <div class="upd-form-grid">
          <div>
            <label class="upd-label" style="color:#92400e;">Credit Limit (₱)</label>
            <input type="number" name="credit_limit" class="upd-input" value="<?php echo (float)($edit_customer['credit_limit'] ?? 0); ?>" min="0" step="0.01" style="border-color:#fbbf24;">
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">Suki Status</label>
            <select name="suki_status" class="upd-input" style="border-color:#fbbf24;">
              <option value="">— Select Status —</option>
              <option value="regular" <?php echo ($edit_customer['suki_status'] ?? '') === 'regular' ? 'selected' : ''; ?>>Regular Customer</option>
              <option value="suki" <?php echo ($edit_customer['suki_status'] ?? '') === 'suki' ? 'selected' : ''; ?>>Suki / Loyal Customer</option>
              <option value="vip" <?php echo ($edit_customer['suki_status'] ?? '') === 'vip' ? 'selected' : ''; ?>>VIP Customer</option>
            </select>
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">Payment Terms</label>
            <select name="payment_terms" class="upd-input" style="border-color:#fbbf24;">
              <option value="cash" <?php echo ($edit_customer['payment_terms'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>>Cash Only</option>
              <option value="7days" <?php echo ($edit_customer['payment_terms'] ?? '') === '7days' ? 'selected' : ''; ?>>7 Days Credit</option>
              <option value="15days" <?php echo ($edit_customer['payment_terms'] ?? '') === '15days' ? 'selected' : ''; ?>>15 Days Credit</option>
              <option value="30days" <?php echo ($edit_customer['payment_terms'] ?? '') === '30days' ? 'selected' : ''; ?>>30 Days Credit</option>
            </select>
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">Type of ID</label>
            <select name="id_type" class="upd-input" style="border-color:#fbbf24;">
              <option value="">— Select ID Type —</option>
              <?php foreach ($gov_id_types as $idt): ?>
              <option value="<?php echo htmlspecialchars($idt); ?>"
                <?php echo ($edit_customer['id_type'] ?? '') === $idt ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($idt); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- ID & CR Upload -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;">
          <div>
            <label class="upd-label" style="color:#92400e;">Upload ID (Front/Copy)</label>
            <?php if (!empty($edit_customer['id_image'])): ?>
            <div style="margin-bottom:6px;font-size:12px;color:#92400e;">
              <i class="fas fa-paperclip"></i> Current:
              <a href="../<?php echo htmlspecialchars($edit_customer['id_image']); ?>" target="_blank" style="color:#b45309;text-decoration:underline;">View ID</a>
            </div>
            <?php endif; ?>
            <input type="file" name="id_image" class="upd-input" accept="image/*,.pdf" style="padding:6px 10px;border-color:#fbbf24;">
            <span style="font-size:11px;color:#92400e;margin-top:3px;display:block;">Leave blank to keep existing</span>
          </div>
          <div>
            <label class="upd-label" style="color:#92400e;">CR / Certificate of Registration</label>
            <?php if (!empty($edit_customer['cr_image'])): ?>
            <div style="margin-bottom:6px;font-size:12px;color:#92400e;">
              <i class="fas fa-paperclip"></i> Current:
              <a href="../<?php echo htmlspecialchars($edit_customer['cr_image']); ?>" target="_blank" style="color:#b45309;text-decoration:underline;">View CR</a>
            </div>
            <?php endif; ?>
            <input type="file" name="cr_image" class="upd-input" accept="image/*,.pdf" style="padding:6px 10px;border-color:#fbbf24;">
            <span style="font-size:11px;color:#92400e;margin-top:3px;display:block;">For business customers</span>
          </div>
        </div>
      </div>
      
      <!-- Customer Account Status (Manager Only) -->
      <div style="margin-bottom:20px;">
        <h3 style="font-size:14px;font-weight:700;color:#002F70;margin:0 0 12px;padding-bottom:8px;border-bottom:2px solid #e9ecef;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-toggle-on"></i> Account Status
        </h3>
        <div style="max-width:280px;">
          <label class="upd-label">Customer Status <span style="color:red">*</span></label>
          <select name="cust_status" class="upd-input">
            <option value="active"   <?php echo strtolower($edit_customer['status'] ?? 'active') === 'active'   ? 'selected' : ''; ?>>✅ Active</option>
            <option value="inactive" <?php echo strtolower($edit_customer['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>⚫ Inactive</option>
            <option value="locked"   <?php echo strtolower($edit_customer['status'] ?? '') === 'locked'   ? 'selected' : ''; ?>>🔒 Locked — Blocked from transactions</option>
          </select>
          <span style="font-size:11px;color:#6c757d;margin-top:4px;display:block;">Locked customers cannot make new credit or purchase transactions.</span>
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="mgrc-btn mgrc-btn-approve" style="padding:10px 22px;font-size:13px;">
          <i class="fas fa-save"></i> Save Changes
        </button>
        <a href="manager_customers.php?section=records" class="mgrc-btn" style="background:#6c757d;color:#fff;padding:10px 18px;font-size:13px;text-decoration:none;">
          <i class="fas fa-times"></i> Cancel
        </a>
      </div>
    </form>
  </div>
</div>
<?php else: ?>

<!-- Customer List (Picker for Edit) -->
<div class="mgrc-card">
  <div class="mgrc-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h2 class="mgrc-title"><i class="fas fa-list"></i> <?php echo $sec_title; ?></h2>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <a href="?section=add" class="mgrc-btn mgrc-btn-approve" style="font-size:11px;padding:6px 12px;text-decoration:none;">
        <i class="fas fa-user-plus"></i> Add Customer
      </a>
      <a href="?section=records&export=csv" class="mgrc-btn" style="background:#28a745;color:#fff;font-size:11px;padding:6px 12px;text-decoration:none;">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
      <a href="?section=records&export=excel" class="mgrc-btn" style="background:#1f7a3e;color:#fff;font-size:11px;padding:6px 12px;text-decoration:none;">
        <i class="fas fa-file-excel"></i> Export Excel
      </a>
    </div>
  </div>
  
  <!-- Summary Cards -->
  <div style="display:flex;gap:12px;padding:14px 18px;background:#f9fafb;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;">
    <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;">
      <div style="font-size:18px;font-weight:800;color:#002F70;"><?php echo $mgr_total; ?></div>
      <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Customers</div>
    </div>
    <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #28a745;border-radius:6px;padding:10px 14px;">
      <div style="font-size:18px;font-weight:800;color:#28a745;"><?php echo $mgr_active; ?></div>
      <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Active Accounts</div>
    </div>
    <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #7c3aed;border-radius:6px;padding:10px 14px;">
      <div style="font-size:18px;font-weight:800;color:#7c3aed;"><?php echo $mgr_locked; ?></div>
      <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Locked Accounts</div>
    </div>
    <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #dc3545;border-radius:6px;padding:10px 14px;">
      <div style="font-size:18px;font-weight:800;color:#dc3545;">₱<?php echo number_format($mgr_total_balance, 2); ?></div>
      <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Balance</div>
    </div>
  </div>
  
  <div class="mgrc-body">
    <input class="mgrc-search" id="recordSearch" placeholder="&#128269; Search by name, contact, or ID type..." oninput="filterRows('recordSearch','recordTable')">
    <div style="overflow-x:auto;">
      <table class="mgrc-table" id="recordTable">
        <thead><tr>
          <th>ID</th>
          <th>Customer Name</th>
          <th>Contact</th>
          <th>Address</th>
          <th>ID Type</th>
          <th>Suki Status</th>
          <th>Payment Terms</th>
          <th>Credit Limit</th>
          <th>Balance</th>
          <th>Status</th>
          <th>Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($records_customers)): ?>
          <tr><td colspan="11" class="mgrc-empty"><i class="fas fa-users"></i>No customers yet. Start adding customers to build your directory.</td></tr>
        <?php else: foreach ($records_customers as $c): 
          $suki_badge_color = match($c['suki_status'] ?? 'regular') {
            'vip' => '#9c27b0',
            'suki' => '#ff9800',
            default => '#6c757d'
          };
          $suki_display = match($c['suki_status'] ?? 'regular') {
            'vip' => 'VIP',
            'suki' => 'Suki',
            default => 'Regular'
          };
        ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'] . ' ' . ($c['contact_number'] ?? '') . ' ' . ($c['id_type'] ?? ''))); ?>">
            <td style="color:#6c757d;font-size:12px;font-weight:600;">#<?php echo (int)$c['id']; ?></td>
            <td><strong style="color:#002F70;"><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td style="font-size:13px;"><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
            <td style="font-size:12px;color:#6c757d;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($c['address'] ?? ''); ?>">
              <?php echo htmlspecialchars($c['address'] ?? '—'); ?>
            </td>
            <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($c['id_type'] ?? '—'); ?></td>
            <td>
              <span style="color:<?php echo $suki_badge_color; ?>;font-weight:700;font-size:12px;">
                <?php echo $suki_display; ?>
              </span>
            </td>
            <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars(ucfirst($c['payment_terms'] ?? 'cash')); ?></td>
            <td style="font-weight:600;">₱<?php echo number_format((float)$c['credit_limit'], 2); ?></td>
            <?php
              $bal = (float)$c['balance'];
              $bal_color = $bal > 0 ? '#dc3545' : '#28a745';
            ?>
            <td style="color:<?php echo $bal_color; ?>;font-weight:700;">
              ₱<?php echo number_format($bal, 2); ?>
            </td>
            <td><?php
              $cstatus = strtolower($c['status'] ?? 'active');
              $badge_cls = match($cstatus) {
                'active'   => 'badge-active',
                'locked'   => 'badge-locked',
                default    => 'badge-inactive',
              };
            ?><span class="<?php echo $badge_cls; ?>"><?php echo htmlspecialchars(ucfirst($cstatus)); ?></span></td>
            <td>
              <a href="manager_customers.php?section=records&customer_id=<?php echo (int)$c['id']; ?>"
                 class="mgrc-btn mgrc-btn-view" style="font-size:11px;min-width:auto;">
                <i class="fas fa-edit"></i> Edit
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ===== SECTION: BALANCES ===== -->
<?php if ($section === 'balances'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-wallet"></i> <?php echo $sec_title; ?></h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($balance_customers); ?> customers with credit</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="balSearch" placeholder="&#128269; Search customers..." oninput="filterRows('balSearch', 'balTable')">
    
    <?php if (empty($balance_customers)): ?>
      <div class="mgrc-empty"><i class="fas fa-wallet"></i><strong>No customers with credit lines found</strong></div>
    <?php else: 
      // Calculate summary totals
      $total_credit_limit = 0;
      $total_outstanding = 0;
      foreach ($balance_customers as $c) {
        $total_credit_limit += (float)($c['credit_limit'] ?? 0);
        $total_outstanding += (float)($c['balance'] ?? 0);
      }
    ?>
    
    <!-- Summary Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
      <div style="background:linear-gradient(135deg,#fff 0%,#e3f2fd 100%);border:1px solid #90caf9;border-radius:10px;padding:18px;">
        <div style="font-size:12px;color:#1976d2;font-weight:600;margin-bottom:6px;">Total Credit Limit</div>
        <div style="font-size:26px;font-weight:700;color:#1565c0;">₱<?php echo number_format($total_credit_limit, 2); ?></div>
      </div>
      <div style="background:linear-gradient(135deg,#fff 0%,#ffebee 100%);border:1px solid #ef9a9a;border-radius:10px;padding:18px;">
        <div style="font-size:12px;color:#c62828;font-weight:600;margin-bottom:6px;">Total Outstanding</div>
        <div style="font-size:26px;font-weight:700;color:#b71c1c;">₱<?php echo number_format($total_outstanding, 2); ?></div>
      </div>
      <div style="background:linear-gradient(135deg,#fff 0%,#e8f5e9 100%);border:1px solid #a5d6a7;border-radius:10px;padding:18px;">
        <div style="font-size:12px;color:#2e7d32;font-weight:600;margin-bottom:6px;">Available Credit</div>
        <div style="font-size:26px;font-weight:700;color:#1b5e20;">₱<?php echo number_format($total_credit_limit - $total_outstanding, 2); ?></div>
      </div>
    </div>
    
    <div style="overflow-x:auto;">
      <table class="mgrc-table" id="balTable">
        <thead>
          <tr>
            <th>Name</th>
            <th>Contact</th>
            <th>Credit Limit</th>
            <th>Outstanding Balance</th>
            <th>Available Credit</th>
            <th>Utilization</th>
            <th>Last Transaction</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($balance_customers as $c):
          $bal = (float)($c['balance'] ?? 0);
          $lim = (float)($c['credit_limit'] ?? 0);
          $avail_credit = max(0, $lim - $bal);
          $pct = $lim > 0 ? min(100, round($bal / $lim * 100, 1)) : 0;
          $color = $bal >= $lim ? '#dc3545' : ($pct >= 80 ? '#fd7e14' : '#28a745');
          $row_class = $bal >= $lim ? 'row-over-limit' : ($pct >= 80 ? 'row-near-limit' : '');
          $last_txn = $c['last_txn_date'] ? date('M d, Y', strtotime($c['last_txn_date'])) : '—';
        ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>" class="<?php echo $row_class; ?>">
            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
            <td>₱<?php echo number_format($lim, 2); ?></td>
            <td style="color:<?php echo $bal>0?'#dc3545':'#6c757d'; ?>;font-weight:700;">₱<?php echo number_format($bal, 2); ?></td>
            <td style="color:<?php echo $color; ?>;font-weight:700;">₱<?php echo number_format($avail_credit, 2); ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="background:#e9ecef;border-radius:4px;height:6px;width:60px;" title="<?php echo $pct; ?>% used">
                  <div style="background:<?php echo $color; ?>;height:6px;border-radius:4px;width:<?php echo $pct; ?>%;"></div>
                </div>
                <span style="font-size:11px;color:#6c757d;font-weight:700;"><?php echo $pct; ?>%</span>
              </div>
            </td>
            <td style="font-size:12px;color:#6c757d;"><?php echo $last_txn; ?></td>
            <td>
              <button class="mgrc-btn mgrc-btn-view" onclick="openPaymentModal(<?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['name'])); ?>', <?php echo $bal; ?>)" style="font-size:11px;padding:6px 12px;">
                <i class="fas fa-dollar-sign"></i> Record Payment
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal-overlay" id="paymentModal">
  <div class="modal-box">
    <div class="modal-title">Record Payment</div>
    <div id="paymentCustomerName" style="font-size:14px;color:#002F70;font-weight:700;margin-bottom:16px;"></div>
    <input type="hidden" id="paymentCustomerId">
    <input type="hidden" id="paymentOutstanding">
    
    <label class="modal-label">Payment Amount (₱) <span style="color:red">*</span></label>
    <input type="number" id="paymentAmount" class="modal-input" min="0.01" step="0.01" placeholder="0.00">
    
    <label class="modal-label">Reference / Notes <span style="color:red">*</span></label>
    <textarea id="paymentReference" class="modal-input" rows="3" placeholder="e.g., Cash payment, OR #12345, Check #67890"></textarea>
    
    <div id="paymentError" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:10px;margin-bottom:12px;color:#991b1b;font-size:12px;"></div>
    <div id="paymentSuccess" style="display:none;background:#d1fae5;border:1px solid#6ee7b7;border-radius:6px;padding:10px;margin-bottom:12px;color:#065f46;font-size:12px;"></div>
    
    <div class="modal-actions">
      <button type="button" class="mgrc-btn" style="background:#6c757d;color:#fff;" onclick="closePaymentModal()">Cancel</button>
      <button type="button" class="mgrc-btn mgrc-btn-approve" onclick="submitPayment()">
        <i class="fas fa-check"></i> Record Payment
      </button>
    </div>
  </div>
</div>

<style>
.row-over-limit td { background:#ffebee !important; }
.row-near-limit td { background:#fff3e0 !important; }
</style>
<?php endif; ?>

<!-- ===== SECTION: CUSTOMER HISTORY ===== -->
<?php if ($section === 'history'): ?>
<div class="mgrc-card">
  <div class="mgrc-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div style="display:flex;align-items:center;gap:12px;">
      <a href="manager_customers.php?section=records" class="mgrc-btn" style="background:#6c757d;color:#fff;font-size:11px;padding:6px 12px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
        <i class="fas fa-arrow-left"></i> Back to List
      </a>
      <h2 class="mgrc-title" style="margin:0;"><i class="fas fa-history"></i> <?php echo $sec_title; ?></h2>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <span style="font-size:13px;color:#6c757d;margin-right:8px;"><?php echo count($history_transactions); ?> transactions</span>
      <?php if (!empty($history_transactions)): ?>
      <a href="?section=history&export=csv&start_date=<?php echo urlencode($history_start); ?>&end_date=<?php echo urlencode($history_end); ?>&customer_filter=<?php echo $history_customer_filter; ?>" 
         class="mgrc-btn" style="background:#28a745;color:#fff;font-size:11px;padding:6px 12px;text-decoration:none;">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
      <a href="?section=history&export=excel&start_date=<?php echo urlencode($history_start); ?>&end_date=<?php echo urlencode($history_end); ?>&customer_filter=<?php echo $history_customer_filter; ?>" 
         class="mgrc-btn" style="background:#1f7a3e;color:#fff;font-size:11px;padding:6px 12px;text-decoration:none;">
        <i class="fas fa-file-excel"></i> Export Excel
      </a>
      <button onclick="printManagerHistoryPDF()" class="mgrc-btn" style="background:#dc3545;color:#fff;font-size:11px;padding:6px 12px;border:none;cursor:pointer;">
        <i class="fas fa-file-pdf"></i> Export PDF
      </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="mgrc-body">
    <!-- Filter Bar -->
    <form method="GET" action="manager_customers.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;padding:16px;background:#f8f9fa;border-radius:8px;">
      <input type="hidden" name="section" value="history">
      
      <div>
        <label class="upd-label">Customer</label>
        <select name="customer_filter" class="upd-input">
          <option value="0">All Customers</option>
          <?php foreach ($history_customers as $hc): ?>
          <option value="<?php echo $hc['id']; ?>" <?php echo $history_customer_filter == $hc['id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($hc['name']); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div>
        <label class="upd-label">Start Date</label>
        <input type="date" name="start_date" class="upd-input" value="<?php echo htmlspecialchars($history_start); ?>">
      </div>
      
      <div>
        <label class="upd-label">End Date</label>
        <input type="date" name="end_date" class="upd-input" value="<?php echo htmlspecialchars($history_end); ?>">
      </div>
      
      <div style="display:flex;align-items:flex-end;">
        <button type="submit" class="mgrc-btn mgrc-btn-approve" style="width:100%;">
          <i class="fas fa-filter"></i> Apply Filters
        </button>
      </div>
    </form>
    
    <!-- History KPI summary cards -->
    <?php if (!empty($history_transactions)): ?>
    <div id="mgrHistSummaryCards" style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
      <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;">
        <div style="font-size:18px;font-weight:800;color:#002F70;"><?php echo $hist_total_count; ?></div>
        <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Transactions</div>
      </div>
      <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #dc3545;border-radius:6px;padding:10px 14px;">
        <div style="font-size:18px;font-weight:800;color:#dc3545;">₱<?php echo number_format($hist_total_sales, 2); ?></div>
        <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Credit Sales</div>
      </div>
      <div style="flex:1;min-width:145px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #28a745;border-radius:6px;padding:10px 14px;">
        <div style="font-size:18px;font-weight:800;color:#28a745;">₱<?php echo number_format($hist_total_payments, 2); ?></div>
        <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Payments</div>
      </div>
    </div>
    <?php endif; ?>
    
    <?php if (empty($history_transactions)): ?>
      <div class="mgrc-empty">
        <i class="fas fa-history"></i>
        <strong>No transactions found</strong>
        <p style="font-size:12px;margin-top:8px;">Try adjusting your date range or customer filter.</p>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="mgrc-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Reference</th>
            <th>Type</th>
            <th style="text-align:right;">Amount</th>
            <th style="text-align:right;">Running Balance</th>
            <th>Description</th>
            <th>Recorded By</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($history_transactions as $txn): 
          $txn_date = date('M d, Y h:i A', strtotime($txn['txn_date']));
          $txn_type = $txn['txn_type'];
          $is_payment = ($txn_type === 'Payment');
          $badge_class = $is_payment ? 'badge-approved' : ($txn_type === 'Adjustment' ? 'badge-pending' : 'badge-active');
          $amount_color = $is_payment ? '#28a745' : '#dc3545';
          $amount_prefix = $is_payment ? '+ ₱' : '- ₱';
        ?>
          <tr>
            <td style="font-size:12px;color:#6c757d;"><?php echo $txn_date; ?></td>
            <td style="font-family:monospace;font-size:12px;color:#002F70;font-weight:700;"><?php echo htmlspecialchars($txn['reference_no']); ?></td>
            <td><span class="<?php echo $badge_class; ?>"><?php echo htmlspecialchars($txn_type); ?></span></td>
            <td style="font-weight:700;color:<?php echo $amount_color; ?>;text-align:right;">
              <?php echo $amount_prefix . number_format((float)$txn['amount'], 2); ?>
            </td>
            <td style="font-weight:700;color:#002F70;text-align:right;">
              ₱<?php echo number_format((float)($txn['running_balance'] ?? 0), 2); ?>
            </td>
            <td style="font-size:12px;color:#6c757d;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($txn['description'] ?? ''); ?>">
              <?php echo htmlspecialchars($txn['description'] ?? '—'); ?>
            </td>
            <td style="font-size:12px;"><?php echo htmlspecialchars($txn['staff_name']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: VALIDATION & OVERSIGHT ===== -->
<?php if ($section === 'validation'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-shield"></i> Pending New Customers</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($pending_new_customers); ?> pending</span>
  </div>
  <div class="mgrc-body">
    <div style="overflow-x:auto;">
      <table class="mgrc-table">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Contact</th><th>ID Type</th><th>Date Added</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($pending_new_customers)): ?>
          <tr><td colspan="6" class="mgrc-empty"><i class="fas fa-check-circle"></i>No pending new customers.</td></tr>
        <?php else: foreach ($pending_new_customers as $c): ?>
          <tr>
            <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$c['id']; ?></td>
            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
            <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($c['id_type'] ?? '—'); ?></td>
            <td><?php echo date('M d, Y h:i A', strtotime($c['created_at'])); ?></td>
            <td>
              <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
                <button class="mgrc-btn mgrc-btn-approve" onclick="openModal('validate', <?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['name'])); ?>', 'approved')"><i class="fas fa-check"></i> Approve</button>
                <button class="mgrc-btn mgrc-btn-reject" onclick="openModal('validate', <?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['name'])); ?>', 'rejected')"><i class="fas fa-times"></i> Reject</button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-file-signature"></i> Pending Update Requests</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($pending_update_requests); ?> requests</span>
  </div>
  <div class="mgrc-body">
    <div style="overflow-x:auto;">
      <table class="mgrc-table">
        <thead><tr>
          <th>Req ID</th><th>Customer</th><th>Requested By</th><th>Field to Update</th><th>Old Value</th><th>New Value</th><th>Date</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($pending_update_requests)): ?>
          <tr><td colspan="8" class="mgrc-empty"><i class="fas fa-check-circle"></i>No pending update requests.</td></tr>
        <?php else: foreach ($pending_update_requests as $r): ?>
          <tr>
            <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$r['id']; ?></td>
            <td><strong><?php echo htmlspecialchars($r['customer_name']); ?></strong></td>
            <td><?php echo htmlspecialchars($r['staff_name']); ?></td>
            <td style="font-weight:700;color:#002F70;"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$r['field_name']))); ?></td>
            <td style="color:#dc3545;"><del><?php echo htmlspecialchars($r['old_value'] ?? '—'); ?></del></td>
            <td style="color:#28a745;font-weight:700;"><?php echo htmlspecialchars($r['new_value']); ?></td>
            <td><?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?></td>
            <td>
              <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
                <button class="mgrc-btn mgrc-btn-approve" onclick="openModal('review', <?php echo $r['id']; ?>, 'Update for <?php echo addslashes(htmlspecialchars($r['customer_name'])); ?>', 'approved')"><i class="fas fa-check"></i> Approve</button>
                <button class="mgrc-btn mgrc-btn-reject" onclick="openModal('review', <?php echo $r['id']; ?>, 'Update for <?php echo addslashes(htmlspecialchars($r['customer_name'])); ?>', 'rejected')"><i class="fas fa-times"></i> Reject</button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: TRANSACTIONS OVERSIGHT ===== -->
<?php if ($section === 'transactions'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title">
      <i class="fas fa-receipt"></i> 
      Transactions: <?php echo $transaction_customer ? htmlspecialchars($transaction_customer['name']) : 'Unknown Customer'; ?>
    </h2>
    <a href="manager_customers.php?section=balances" class="mgrc-btn" style="background:#6c757d;color:#fff;font-size:12px;text-decoration:none;">
      <i class="fas fa-arrow-left"></i> Back to Balances
    </a>
  </div>
  <div class="mgrc-body">
    <?php if (!$transaction_customer): ?>
      <div class="mgrc-empty"><i class="fas fa-user-times"></i><strong>Customer not found.</strong></div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="mgrc-table">
          <thead><tr>
            <th>Txn ID</th><th>Type</th><th>Total Amount</th><th>Processed By</th><th>Date</th><th>Status</th>
          </tr></thead>
          <tbody>
          <?php if (empty($transactions)): ?>
            <tr><td colspan="6" class="mgrc-empty"><i class="fas fa-receipt"></i>No transactions found for this customer.</td></tr>
          <?php else: foreach ($transactions as $t): ?>
            <tr>
              <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$t['id']; ?></td>
              <td style="font-weight:700;color:#002F70;"><?php echo htmlspecialchars(ucfirst($t['transaction_type'])); ?></td>
              <td>₱<?php echo number_format((float)$t['total_amount'], 2); ?></td>
              <td><?php echo htmlspecialchars($t['staff_name'] ?? '—'); ?></td>
              <td><?php echo date('M d, Y h:i A', strtotime($t['created_at'])); ?></td>
              <td><span class="badge-<?php echo strtolower($t['status']); ?>"><?php echo htmlspecialchars(ucfirst($t['status'])); ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== APPROVAL MODAL ===== -->
<div class="modal-overlay" id="actionModal">
  <div class="modal-box">
    <div class="modal-title" id="modalTitle">Review Customer</div>
    <form method="POST" action="manager_customers.php?section=<?php echo $section; ?>">
      <input type="hidden" name="action" id="modalAction" value="">
      <input type="hidden" name="customer_id" id="modalCustomerId" value="">
      <input type="hidden" name="request_id" id="modalRequestId" value="">
      <input type="hidden" name="status" id="modalStatus" value="">
      <p id="modalDesc" style="font-size:13px;color:#6c757d;margin-bottom:14px;"></p>
      <label class="modal-label">Manager Notes (optional)</label>
      <textarea name="notes" id="modalNotes" class="modal-input" rows="3" placeholder="Add notes for this decision..."></textarea>
      <div class="modal-actions">
        <button type="button" class="mgrc-btn" style="background:#6c757d;color:#fff;" onclick="closeModal()">Cancel</button>
        <button type="submit" class="mgrc-btn" id="modalSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
function filterRows(inputId, tableId) {
    const q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr[data-search]').forEach(function(row) {
        row.style.display = row.getAttribute('data-search').includes(q) ? '' : 'none';
    });
}

function filterCards(inputId) {
    const q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll('#balGrid .mgrc-bal-card').forEach(function(card) {
        card.style.display = (card.getAttribute('data-name') || '').includes(q) ? '' : 'none';
    });
}

function openModal(type, id, name, status) {
    const modal = document.getElementById('actionModal');
    const isApprove = status === 'approved';
    document.getElementById('modalTitle').textContent = (isApprove ? 'Approve' : 'Reject') + ' — ' + name;
    document.getElementById('modalDesc').textContent = isApprove
        ? 'This will approve the record and mark it as active.'
        : 'This will reject the record. Please add notes explaining the reason.';
    document.getElementById('modalStatus').value = status;
    document.getElementById('modalNotes').value = '';
    const btn = document.getElementById('modalSubmitBtn');
    btn.textContent = isApprove ? 'Approve' : 'Reject';
    btn.className = 'mgrc-btn ' + (isApprove ? 'mgrc-btn-approve' : 'mgrc-btn-reject');
    if (type === 'validate') {
        document.getElementById('modalAction').value = 'validate_customer';
        document.getElementById('modalCustomerId').value = id;
        document.getElementById('modalRequestId').value = '';
    } else {
        document.getElementById('modalAction').value = 'review_update';
        document.getElementById('modalRequestId').value = id;
        document.getElementById('modalCustomerId').value = '';
    }
    modal.classList.add('open');
}

function closeModal() {
    document.getElementById('actionModal').classList.remove('open');
}

document.getElementById('actionModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ── Payment Modal Functions ─────────────────────────────────────────────────
function openPaymentModal(customerId, customerName, outstanding) {
    document.getElementById('paymentCustomerId').value = customerId;
    document.getElementById('paymentOutstanding').value = outstanding;
    document.getElementById('paymentCustomerName').textContent = customerName + ' — Outstanding: ₱' + parseFloat(outstanding).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('paymentAmount').value = '';
    document.getElementById('paymentReference').value = '';
    document.getElementById('paymentError').style.display = 'none';
    document.getElementById('paymentSuccess').style.display = 'none';
    document.getElementById('paymentModal').classList.add('open');
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('open');
}

document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});

function submitPayment() {
    const customerId = parseInt(document.getElementById('paymentCustomerId').value);
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const reference = document.getElementById('paymentReference').value.trim();
    const outstanding = parseFloat(document.getElementById('paymentOutstanding').value);
    const errorDiv = document.getElementById('paymentError');
    const successDiv = document.getElementById('paymentSuccess');
    
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    // Validation
    if (!amount || amount <= 0) {
        errorDiv.textContent = 'Payment amount must be greater than 0.';
        errorDiv.style.display = 'block';
        return;
    }
    if (reference.length < 3) {
        errorDiv.textContent = 'Reference must be at least 3 characters.';
        errorDiv.style.display = 'block';
        return;
    }
    
    // Check for overpayment
    if (amount > outstanding) {
        const excess = amount - outstanding;
        if (!confirm('Overpayment detected! Amount exceeds outstanding balance by ₱' + excess.toFixed(2) + '. Continue?')) {
            return;
        }
    }
    
    // Submit via AJAX
    const formData = new FormData();
    formData.append('action', 'validate_payment');
    formData.append('customer_id', customerId);
    formData.append('amount', amount);
    formData.append('reference', reference);
    formData.append('force_overpayment', amount > outstanding ? '1' : '0');
    
    fetch('manager_customers.php?section=balances', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            successDiv.textContent = 'Payment recorded successfully! New balance: ₱' + parseFloat(data.new_balance).toFixed(2);
            successDiv.style.display = 'block';
            setTimeout(function() {
                closePaymentModal();
                location.reload();
            }, 1500);
        } else if (data.overpayment) {
            if (confirm('Overpayment detected! Amount exceeds outstanding balance by ₱' + data.excess.toFixed(2) + '. Continue?')) {
                // Resubmit with force flag
                formData.set('force_overpayment', '1');
                fetch('manager_customers.php?section=balances', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        successDiv.textContent = 'Payment recorded successfully! New balance: ₱' + parseFloat(d.new_balance).toFixed(2);
                        successDiv.style.display = 'block';
                        setTimeout(function() {
                            closePaymentModal();
                            location.reload();
                        }, 1500);
                    } else {
                        errorDiv.textContent = d.error || 'An error occurred.';
                        errorDiv.style.display = 'block';
                    }
                });
            }
        } else {
            errorDiv.textContent = data.error || 'An error occurred.';
            errorDiv.style.display = 'block';
        }
    })
    .catch(error => {
        errorDiv.textContent = 'Network error. Please try again.';
        errorDiv.style.display = 'block';
    });
}

function printManagerHistoryPDF() {
    const printContent = document.getElementById('mgrHistSummaryCards') ? 
        (document.getElementById('mgrHistSummaryCards').outerHTML || '') : '';
    const tableEl = document.querySelector('.mgrc-table');
    if (!tableEl) { alert('No data to print.'); return; }
    
    const custSel = document.querySelector('select[name="customer_filter"]');
    const custName = custSel ? custSel.options[custSel.selectedIndex].text : 'All Customers';
    const startD = document.querySelector('input[name="start_date"]').value;
    const endD = document.querySelector('input[name="end_date"]').value;
    
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html>
<html><head>
<title>Customer History - \${custName}</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; color: #333; }
  h2 { color: #002F6C; margin-bottom: 4px; }
  .meta { color: #666; font-size: 11px; margin-bottom: 16px; }
  .cards { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
  .card { flex: 1; min-width: 120px; border: 1px solid #ddd; border-left: 4px solid #002F6C; padding: 8px 12px; border-radius: 4px; }
  .card-val { font-size: 18px; font-weight: 800; color: #002F6C; }
  .card-lbl { font-size: 10px; text-transform: uppercase; color: #888; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #002F6C; color: #fff; padding: 8px; text-align: left; }
  td { padding: 7px 8px; border-bottom: 1px solid #eee; }
  tr:last-child td { border-bottom: none; }
  @media print { body { margin: 0; } }
</style>
</head><body>
<h2>Customer Transaction History</h2>
<div class="meta">Customer: <strong>\${custName}</strong> &nbsp;|&nbsp; Range: \${startD} to \${endD} &nbsp;|&nbsp; Printed: \${new Date().toLocaleString()}</div>
\${printContent}
<table style="width:100%;border-collapse:collapse;font-size:11px;">\${tableEl.innerHTML}</table>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
}
</script>

<div style="height: 80px;"></div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
