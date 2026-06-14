<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('customers')) {
    render_module_disabled_page('Customers');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php'); exit;
}

// ── Resolve active section (driven by sidebar sub-menu) ───────────────────────
// 'add'     → Add New Customer form
// 'list'    → Customer List (basic info)
// 'history' → Customer History (own transactions)
// 'encode'  → legacy alias for 'list'
// 'linkage' → legacy Transaction Linkage (kept for backward compat)
$valid_sections = ['add', 'list', 'encode', 'history', 'linkage', 'edit'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'list';

// Normalize legacy alias
if ($section === 'encode') $section = 'list';

// Block any direct attempt to access removed sections
if (isset($_GET['section']) && in_array($_GET['section'], ['update', 'balances'])) {
    header('Location: customers.php?section=list'); exit;
}

// ── Page ID for sidebar sub-item highlighting ─────────────────────────────────
$page_id = match($section) {
    'add'     => 'customer_add',
    'history' => 'customer_history',
    'edit'    => 'customer_list',
    default   => 'customer_list',
};

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

// ── Ensure customers table has required columns (add if missing) ──────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('contact_number', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN contact_number VARCHAR(50) NULL AFTER name");
    }
    if (!in_array('id_type', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN id_type VARCHAR(100) NULL AFTER contact_number");
    }
    if (!in_array('id_number', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN id_number VARCHAR(100) NULL AFTER id_type");
    }
    if (!in_array('id_image', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN id_image VARCHAR(255) NULL AFTER id_number");
    }
    if (!in_array('cr_image', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN cr_image VARCHAR(255) NULL AFTER id_image");
    }
    if (!in_array('credit_limit', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(12,2) DEFAULT 0.00 AFTER id_number");
    }
    if (!in_array('balance', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN balance DECIMAL(12,2) DEFAULT 0.00 AFTER credit_limit");
    }
    if (!in_array('status', $cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER balance");
    }
} catch (Exception $e) { /* silent — table may not exist yet */ }

// ── Handle POST: encode new customer ─────────────────────────────────────────
$flash_success = $flash_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'encode_customer') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $name       = trim($first_name . ' ' . $last_name);
    $contact    = trim($_POST['contact']    ?? '');
    $address    = trim($_POST['address']    ?? '');
    $id_type    = trim($_POST['id_type']    ?? '');
    // credit_limit intentionally omitted — Manager sets this, not Staff

    // Handle ID image upload
    $id_image_path = null;
    if (!empty($_FILES['id_image']['name'])) {
        $upload_dir = __DIR__ . '/../uploads/customer_ids/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','pdf','webp'];
        if (in_array($ext, $allowed)) {
            $fname = 'id_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['id_image']['tmp_name'], $upload_dir . $fname)) {
                $id_image_path = 'uploads/customer_ids/' . $fname;
            }
        }
    }

    // Handle CR image upload
    $cr_image_path = null;
    if (!empty($_FILES['cr_image']['name'])) {
        $upload_dir = __DIR__ . '/../uploads/customer_ids/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['cr_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','pdf','webp'];
        if (in_array($ext, $allowed)) {
            $fname = 'cr_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['cr_image']['tmp_name'], $upload_dir . $fname)) {
                $cr_image_path = 'uploads/customer_ids/' . $fname;
            }
        }
    }

    if (!$name) {
        $flash_error = 'First name and Last name are required.';
    } else {
        try {
            $ins_cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
            $col_list = ['name', 'station_id', 'status', 'created_at'];
            $val_list = [$name, $station_id, 'active', date('Y-m-d H:i:s')];
            if (in_array('contact_number', $ins_cols)) { $col_list[] = 'contact_number'; $val_list[] = $contact; }
            if (in_array('address',        $ins_cols)) { $col_list[] = 'address';        $val_list[] = $address; }
            if (in_array('id_type',        $ins_cols)) { $col_list[] = 'id_type';        $val_list[] = $id_type; }
            if (in_array('id_image',       $ins_cols)) { $col_list[] = 'id_image';       $val_list[] = $id_image_path; }
            if (in_array('cr_image',       $ins_cols)) { $col_list[] = 'cr_image';       $val_list[] = $cr_image_path; }
            // credit_limit intentionally omitted — Manager only sets this
            if (in_array('balance',        $ins_cols)) { $col_list[] = 'balance';         $val_list[] = 0; }
            $placeholders = implode(',', array_fill(0, count($col_list), '?'));
            $pdo->prepare("INSERT INTO customers (" . implode(',', $col_list) . ") VALUES ($placeholders)")
                ->execute($val_list);
            
            write_audit_log($pdo, 'Create', "Staff registered new customer: $name under Station #$station_id", 'customers', $pdo->lastInsertId(), 'success');
            
            $_SESSION['success'] = "Customer \"$name\" added successfully.";
            header('Location: customers.php?section=list'); exit;
        } catch (Exception $e) {
            $flash_error = 'Error saving customer: ' . $e->getMessage();
        }
    }
}

// ── Handle POST: update customer (Staff General Info) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_customer') {
    $cid        = (int)($_POST['customer_id'] ?? 0);
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $name       = trim($first_name . ' ' . $last_name);
    $contact    = trim($_POST['contact']    ?? '');
    $address    = trim($_POST['address']    ?? '');
    $cust_status= trim($_POST['status']     ?? 'active');

    if (!$name) {
        $flash_error = 'First name and Last name are required.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE customers SET name=?, contact_number=?, address=?, status=? WHERE id=? AND station_id=?");
            $stmt->execute([$name, $contact, $address, $cust_status, $cid, $station_id]);
            
            write_audit_log($pdo, 'Update', "Staff updated customer #$cid: $name general info", 'customers', $cid, 'success');
            
            $_SESSION['success'] = "Customer \"$name\" updated successfully.";
            header('Location: customers.php?section=list'); exit;
        } catch (Exception $e) {
            $flash_error = 'Error updating customer: ' . $e->getMessage();
        }
    }
}

// ── Fetch Edit Customer Info if applicable ──────────────────────────────────
$edit_customer = null;
if ($section === 'edit') {
    $edit_id = (int)($_GET['customer_id'] ?? 0);
    if ($edit_id > 0) {
        try {
            $s = $pdo->prepare("SELECT * FROM customers WHERE id=? AND station_id=?");
            $s->execute([$edit_id, $station_id]);
            $edit_customer = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
    if (!$edit_customer) {
        header('Location: customers.php?section=list'); exit;
    }
}

// ── CSV Export Handler ─────────────────────────────────────────────────────
$_hist_export_id = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
if ($section === 'history' && isset($_GET['export']) && $_GET['export'] === 'csv' && $_hist_export_id) {
    // We need to re-run the history query here since it hasn't been run yet
    $exp_params = [':station_id' => $station_id, ':customer_id' => $_hist_export_id];
    $exp_where = "cct.station_id = :station_id AND cct.customer_id = :customer_id";
    if (!empty($_GET['hist_date_from'])) { $exp_where .= " AND cct.created_at >= :date_from"; $exp_params[':date_from'] = $_GET['hist_date_from'] . ' 00:00:00'; }
    if (!empty($_GET['hist_date_to']))   { $exp_where .= " AND cct.created_at <= :date_to";   $exp_params[':date_to']   = $_GET['hist_date_to'] . ' 23:59:59'; }
    try {
        $exp_name_s = $pdo->prepare("SELECT name FROM customers WHERE id=? AND station_id=?");
        $exp_name_s->execute([$_hist_export_id, $station_id]);
        $exp_cust_name = $exp_name_s->fetchColumn() ?: 'Customer';
        $exp_s = $pdo->prepare("SELECT cct.created_at, cct.transaction_id, cct.transaction_type, cct.amount, cct.running_balance, cct.description, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS recorded_by FROM customer_credit_transactions cct LEFT JOIN users u ON u.id = cct.created_by WHERE $exp_where ORDER BY cct.created_at DESC LIMIT 500");
        $exp_s->execute($exp_params);
        $exp_rows = $exp_s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $exp_rows = []; $exp_cust_name = 'Customer'; }
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_history_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Customer Transaction History']);
    fputcsv($out, ['Customer:', $exp_cust_name]);
    fputcsv($out, ['Station:', 'Station #' . $station_id]);
    fputcsv($out, ['Exported:', date('F d, Y h:i A')]);
    fputcsv($out, ['Total Records:', count($exp_rows)]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Reference No.', 'Type', 'Amount (₱)', 'Running Balance (₱)', 'Remarks', 'Recorded By']);
    foreach ($exp_rows as $er) {
        fputcsv($out, [
            date('M d, Y h:i A', strtotime($er['created_at'])),
            $er['transaction_id'],
            $er['transaction_type'],
            number_format((float)$er['amount'], 2),
            number_format((float)$er['running_balance'], 2),
            $er['description'] ?? '—',
            $er['recorded_by']
        ]);
    }
    fclose($out);
    exit;
}

// ── History Excel Export Handler ─────────────────────────────────────────────
$hist_selected_id_pre = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
if ($section === 'history' && isset($_GET['export']) && $_GET['export'] === 'excel' && $hist_selected_id_pre) {
    $exp_params = [':station_id' => $station_id, ':customer_id' => $hist_selected_id_pre];
    $exp_where = "cct.station_id = :station_id AND cct.customer_id = :customer_id";
    if (!empty($_GET['hist_date_from'])) { $exp_where .= " AND cct.created_at >= :date_from"; $exp_params[':date_from'] = $_GET['hist_date_from'] . ' 00:00:00'; }
    if (!empty($_GET['hist_date_to']))   { $exp_where .= " AND cct.created_at <= :date_to";   $exp_params[':date_to']   = $_GET['hist_date_to'] . ' 23:59:59'; }
    try {
        $exp_name_s = $pdo->prepare("SELECT name FROM customers WHERE id=? AND station_id=?");
        $exp_name_s->execute([$hist_selected_id_pre, $station_id]);
        $exp_cust_name = $exp_name_s->fetchColumn() ?: 'Customer';
        $exp_s = $pdo->prepare("SELECT cct.created_at, cct.transaction_id, cct.transaction_type, cct.amount, cct.running_balance, cct.description, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS recorded_by FROM customer_credit_transactions cct LEFT JOIN users u ON u.id = cct.created_by WHERE $exp_where ORDER BY cct.created_at DESC LIMIT 500");
        $exp_s->execute($exp_params);
        $exp_rows = $exp_s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $exp_rows = []; $exp_cust_name = 'Customer'; }
    ob_end_clean();
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_history_' . date('Ymd') . '.xls"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Customer Transaction History']);
    fputcsv($out, ['Customer:', $exp_cust_name]);
    fputcsv($out, ['Station:', 'Station #' . $station_id]);
    fputcsv($out, ['Exported:', date('F d, Y h:i A')]);
    fputcsv($out, ['Total Records:', count($exp_rows)]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Reference No.', 'Type', 'Amount (₱)', 'Running Balance (₱)', 'Remarks', 'Recorded By']);
    foreach ($exp_rows as $er) {
        fputcsv($out, [
            date('M d, Y h:i A', strtotime($er['created_at'])),
            $er['transaction_id'],
            $er['transaction_type'],
            number_format((float)$er['amount'], 2),
            number_format((float)$er['running_balance'], 2),
            $er['description'] ?? '—',
            $er['recorded_by']
        ]);
    }
    fclose($out);
    exit;
}

// ── Customer List CSV/Excel Export Handler ────────────────────────────────────
if ($section === 'list' && isset($_GET['export']) && in_array($_GET['export'], ['csv','excel'])) {
    $is_excel = ($_GET['export'] === 'excel');
    try {
        $avail2 = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $sel2_contact  = in_array('contact_number', $avail2) ? 'contact_number' : "'' AS contact_number";
        $sel2_status   = in_array('status',         $avail2) ? 'status'         : "'active' AS status";
        $sel2_id_type  = in_array('id_type',        $avail2) ? 'id_type'        : "'' AS id_type";
        $s2 = $pdo->prepare("SELECT id, name, $sel2_contact, $sel2_id_type, $sel2_status FROM customers WHERE station_id=? ORDER BY name");
        $s2->execute([$station_id]);
        $list_rows = $s2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $list_rows = []; }
    ob_end_clean();
    $filename = 'staff_customer_list_' . date('Y-m-d') . ($is_excel ? '.xls' : '.csv');
    if ($is_excel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out2 = fopen('php://output', 'w');
    fprintf($out2, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out2, ['Staff Customer Directory']);
    fputcsv($out2, ['Station:', 'Station #' . $station_id]);
    fputcsv($out2, ['Exported:', date('F d, Y h:i A')]);
    fputcsv($out2, ['Total Records:', count($list_rows)]);
    fputcsv($out2, []);
    fputcsv($out2, ['ID', 'Customer Name', 'Contact Number', 'ID Type', 'Status']);
    foreach ($list_rows as $lr) {
        fputcsv($out2, [
            $lr['id'],
            $lr['name'],
            $lr['contact_number'] ?? '—',
            $lr['id_type'] ?? '—',
            ucfirst($lr['status'] ?? 'active')
        ]);
    }
    fclose($out2);
    exit;
}

// ── Summary stats for list section ────────────────────────────────────────
$summary_total    = 0;
$summary_active   = 0;
$summary_inactive = 0;
$summary_locked   = 0;
if ($section === 'list') {
    try {
        $ss = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM customers WHERE station_id=? GROUP BY status");
        $ss->execute([$station_id]);
        foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary_total += (int)$row['cnt'];
            if ($row['status'] === 'active')   $summary_active   = (int)$row['cnt'];
            if ($row['status'] === 'inactive') $summary_inactive = (int)$row['cnt'];
            if ($row['status'] === 'locked')   $summary_locked   = (int)$row['cnt'];
        }
    } catch (Exception $e) {}
}

// ── Data fetches ──────────────────────────────────────────────────────────────
$customers = [];
try {
    // Detect available columns to avoid errors on older schemas
    $avail = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $sel_contact  = in_array('contact_number', $avail) ? 'contact_number' : "'' AS contact_number";
    $sel_address  = in_array('address',        $avail) ? 'address'        : "'' AS address";
    $sel_id_type  = in_array('id_type',        $avail) ? 'id_type'        : "'' AS id_type";
    $sel_id_image = in_array('id_image',       $avail) ? 'id_image'       : "'' AS id_image";
    $sel_cr_image = in_array('cr_image',       $avail) ? 'cr_image'       : "'' AS cr_image";
    $sel_status   = in_array('status',         $avail) ? 'status'         : "'active' AS status";
    $s = $pdo->prepare("SELECT id, name, $sel_contact, $sel_address, $sel_id_type, $sel_id_image, $sel_cr_image, $sel_status FROM customers WHERE station_id=? ORDER BY name");
    $s->execute([$station_id]);
    $customers = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Transaction linkage
$linkage_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$job_orders_linked   = [];
$merch_linked        = [];
if ($linkage_customer_id) {
    try {
        // Fetch customer name for fallback matching
        $c_stmt = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
        $c_stmt->execute([$linkage_customer_id]);
        $linkage_customer_name = $c_stmt->fetchColumn();

        // Check if credit_customer_id column exists
        $jo_cols = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
        $has_credit_cid = in_array('credit_customer_id', $jo_cols);
        $has_jo_name = in_array('customer_name', $jo_cols);
        
        $cust_cond = $has_credit_cid
            ? '(customer_id=? OR credit_customer_id=?' . ($has_jo_name && $linkage_customer_name ? ' OR customer_name=?' : '') . ')'
            : '(customer_id=?' . ($has_jo_name && $linkage_customer_name ? ' OR customer_name=?' : '') . ')';
            
        $params = $has_credit_cid
            ? [$station_id, $linkage_customer_id, $linkage_customer_id]
            : [$station_id, $linkage_customer_id];
            
        if ($has_jo_name && $linkage_customer_name) {
            $params[] = $linkage_customer_name;
        }

        $jo_num_col = in_array('job_order_id', $jo_cols) ? 'job_order_id'
                    : (in_array('jo_number', $jo_cols) ? 'jo_number' : 'NULL');
        $svc_col = in_array('service_type', $jo_cols) ? 'service_type'
                 : (in_array('service_description', $jo_cols) ? 'service_description' : "'—'");

        $s = $pdo->prepare("
            SELECT id,
                   COALESCE($jo_num_col, CONCAT('JO-',id)) AS jo_ref,
                   COALESCE($svc_col, '—') AS service,
                   created_at, status
            FROM job_orders
            WHERE station_id=? AND $cust_cond
            ORDER BY created_at DESC
        ");
        $s->execute($params);
        $job_orders_linked = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    try {
        // Check if customer_id exists in merchandise_transactions
        $mt_cols = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('customer_name', $mt_cols)) {
            $date_col = in_array('transaction_date', $mt_cols) ? 'COALESCE(transaction_date, created_at)' : 'created_at';
            
            $mt_cond = "customer_name = ?";
            $mt_params = [$station_id, $linkage_customer_name];
            
            if (in_array('customer_id', $mt_cols)) {
                $mt_cond = "(customer_id = ? OR customer_name = ?)";
                $mt_params = [$station_id, $linkage_customer_id, $linkage_customer_name];
            }
            
            $s = $pdo->prepare("
                SELECT id, customer_name, total_amount,
                       $date_col AS txn_date, payment_method
                FROM merchandise_transactions
                WHERE station_id=? AND $mt_cond
                ORDER BY txn_date DESC
            ");
            $s->execute($mt_params);
            $merch_linked = $s->fetchAll(PDO::FETCH_ASSOC);

            // Compile transaction items and quantity for linkage display
            foreach ($merch_linked as &$mt) {
                $mt['item_sku'] = '—';
                $mt['quantity'] = 0;
                try {
                    $is = $pdo->prepare("
                        SELECT COALESCE(ip.product_name, mti.product_name, 'Item') AS pname, mti.quantity
                        FROM merchandise_transaction_items mti
                        LEFT JOIN inventory_products ip ON ip.id = mti.product_id
                        WHERE mti.transaction_id = ?
                    ");
                    $is->execute([$mt['id']]);
                    $items = $is->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($items)) {
                        $mt['item_sku'] = implode(', ', array_map(fn($i) => $i['pname'] . ' ×' . $i['quantity'], $items));
                        $mt['quantity'] = array_sum(array_column($items, 'quantity'));
                    }
                } catch (Exception $e) {}
            }
            unset($mt);
        }
    } catch (Exception $e) {}
}

// ── Customer History data ─────────────────────────────────────────────────────
$hist_customers      = [];
$hist_selected_id    = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
$hist_filter_type    = $_GET['hist_type']   ?? '';   // 'job_order' | 'merchandise' | ''
$hist_filter_status  = $_GET['hist_status'] ?? '';   // 'Paid' | 'Unpaid' | 'Partial' | ''
$hist_filter_date    = $_GET['hist_date']      ?? '';
$hist_filter_date_from = $_GET['hist_date_from'] ?? $hist_filter_date;
$hist_filter_date_to   = $_GET['hist_date_to']   ?? '';
$hist_records        = [];
$hist_customer_info  = null;
if ($section === 'history') {
    try {
        // All customers for this station (for the selector)
        $s = $pdo->prepare("SELECT id, name, status FROM customers WHERE station_id=? ORDER BY name ASC");
        $s->execute([$station_id]);
        $hist_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if ($hist_selected_id) {
        // Load selected customer info
        foreach ($hist_customers as $hc) {
            if ($hc['id'] === $hist_selected_id) { $hist_customer_info = $hc; break; }
        }

        // ── Query exclusively from customer_credit_transactions canonical ledger ──
        try {
            $params = [
                ':station_id' => $station_id,
                ':customer_id' => $hist_selected_id
            ];
            
            $where_clauses = [
                "cct.station_id = :station_id",
                "cct.customer_id = :customer_id"
            ];
            
            if ($hist_filter_date_from && $hist_filter_date_to) {
                $where_clauses[] = "cct.created_at BETWEEN :date_from AND :date_to";
                $params[':date_from'] = $hist_filter_date_from . ' 00:00:00';
                $params[':date_to'] = $hist_filter_date_to . ' 23:59:59';
            } elseif ($hist_filter_date_from) {
                $where_clauses[] = "cct.created_at >= :date_from";
                $params[':date_from'] = $hist_filter_date_from . ' 00:00:00';
            } elseif ($hist_filter_date_to) {
                $where_clauses[] = "cct.created_at <= :date_to";
                $params[':date_to'] = $hist_filter_date_to . ' 23:59:59';
            }
            
            $where_str = implode(' AND ', $where_clauses);
            
            $query = "
                SELECT
                    cct.created_at AS txn_date,
                    cct.transaction_id AS ref_number,
                    CASE
                        WHEN cct.transaction_id LIKE 'JO-%' OR cct.transaction_id LIKE 'AR-%' THEN 'job_order'
                        ELSE 'merchandise'
                    END AS record_type,
                    cct.amount AS total_amount,
                    cct.amount AS amount_paid,
                    CASE
                        WHEN cct.transaction_type = 'Payment' THEN 'Paid'
                        ELSE 'Unpaid'
                    END AS payment_status,
                    cct.description AS service_label,
                    cct.description AS merch_items_summary
                FROM customer_credit_transactions cct
                WHERE $where_str
                ORDER BY cct.created_at DESC
                LIMIT 500
            ";
            
            $st = $pdo->prepare($query);
            $st->execute($params);
            $hist_records = $st->fetchAll(PDO::FETCH_ASSOC);
            
            // Apply in-memory type filter if set
            if ($hist_filter_type) {
                $hist_records = array_filter($hist_records, fn($r) => $r['record_type'] === $hist_filter_type);
                $hist_records = array_values($hist_records);
            }
            
            // Apply in-memory payment status filter if set
            if ($hist_filter_status) {
                $hist_records = array_filter($hist_records, fn($r) => $r['payment_status'] === $hist_filter_status);
                $hist_records = array_values($hist_records);
            }
            
        } catch (Exception $e) {}
    }
}


// Section titles for page header
$section_titles = [
    'add'      => ['fas fa-user-plus',  'Add New Customer'],
    'list'     => ['fas fa-list',       'Customer List'],
    'history'  => ['fas fa-history',    'Customer History'],
    'linkage'  => ['fas fa-link',       'Transaction Linkage'],
    'edit'     => ['fas fa-user-edit',  'Edit Customer Profile'],
];
[$sec_ico, $sec_title] = $section_titles[$section];

// Flash from redirect
if (!empty($_SESSION['success'])) {
    $flash_success = $_SESSION['success'];
    unset($_SESSION['success']);
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Staff Customer Module — Clean Design Standards ────────────────────── */
:root {
    --staff-blue: #002F70;
    --staff-blue-hover: #f0f4ff;
}

/* Card containers */
.cust-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    border: 1px solid #e5e7eb;
    margin-bottom: 20px;
    overflow: hidden;
}

.cust-card-head {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: #fff;
}

.cust-card-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--staff-blue);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.cust-card-body {
    padding: 20px;
    background: #fff;
}

/* Form elements */
.cust-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media(max-width:768px) {
    .cust-form-grid { grid-template-columns: 1fr; }
}

.cust-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
}

.cust-input,
.cust-search {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    box-sizing: border-box;
    background: #fff;
    transition: all 0.15s;
}

.cust-input:focus,
.cust-search:focus {
    border-color: var(--staff-blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,47,112,.08);
}

.cust-search {
    margin-bottom: 16px;
}

/* Buttons */
.cust-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.cust-btn-primary {
    background: var(--staff-blue);
    color: #fff;
}

.cust-btn-primary:hover {
    background: #001f4d;
    transform: translateY(-1px);
}

.btn-back {
    color: #6b7280;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    transition: all 0.15s;
}

.btn-back:hover {
    background: #f3f4f6;
    color: var(--staff-blue);
}

/* Tables — Staff Standard: Blue headers, white body, no horizontal scroll */
.cust-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.cust-table th {
    background: var(--staff-blue);
    color: #fff;
    padding: 11px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
    white-space: nowrap;
}

.cust-table td {
    padding: 11px 14px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
    background: #fff;
    color: #374151;
}

.cust-table tbody tr:hover td {
    background: var(--staff-blue-hover);
}

.cust-table tbody tr:last-child td {
    border-bottom: none;
}

/* Prevent horizontal scroll */
.cust-card-body,
.cust-table-wrap {
    overflow-x: auto;
    max-width: 100%;
}

/* Status badges — Plain text style */
.badge-active,
.badge-inactive,
.badge-pending,
.badge-completed {
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    display: inline-block;
}

.badge-active {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #d1fae5;
}

.badge-inactive {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fee2e2;
}

.badge-pending {
    background: #fffbeb;
    color: #92400e;
    border: 1px solid #fef3c7;
}

.badge-completed {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #d1fae5;
}

/* Action links */
.edit-link {
    color: var(--staff-blue);
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    padding: 5px 12px;
    border: 1px solid var(--staff-blue);
    border-radius: 5px;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s;
}

.edit-link:hover {
    background: var(--staff-blue);
    color: #fff;
}

/* Info notices */
.readonly-notice {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    padding: 12px 16px;
    font-size: 12px;
    color: #1e40af;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Empty states */
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: #9ca3af;
}

.empty-state i {
    font-size: 2.5rem;
    display: block;
    margin-bottom: 12px;
    color: #d1d5db;
}

/* Tabs (for linkage section) */
.cust-tab {
    padding: 10px 16px;
    border: none;
    background: transparent;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.15s;
}

.cust-tab.active {
    color: var(--staff-blue);
    border-bottom-color: var(--staff-blue);
}

.cust-tab:hover:not(.active) {
    color: #374151;
    background: #f9fafb;
}

.linkage-tab-content {
    padding-top: 8px;
}
</style>

<div class="page-head">
    <div>
        <h1><i class="<?= $sec_ico ?>"></i> <?= $sec_title ?></h1>
        <div class="page-subtitle">
            Station #<?= (int)$station_id ?>
            <?php if ($section === 'add'): ?>&mdash; Encode basic customer details (name, contact, address).
            <?php elseif ($section === 'list'): ?>&mdash; View and manage customer profiles within your station.
            <?php elseif ($section === 'history'): ?>&mdash; View transaction history within your station.
            <?php endif; ?>
        </div>
    </div>
    <?php if ($section === 'list'): ?>
    <a href="customers.php?section=add" class="cust-btn cust-btn-primary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
        <i class="fas fa-user-plus"></i> Add New Customer
    </a>
    <?php endif; ?>
</div>

<?php if ($flash_success): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#065f46;font-weight:600;">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#991b1b;font-weight:600;">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<!-- ══ SECTION: ADD NEW CUSTOMER ═════════════════════════════════════════════ -->
<?php if ($section === 'add'): ?>

<div class="cust-card">
    <div class="cust-card-head">
        <h2 class="cust-card-title"><i class="fas fa-user-plus"></i> New Customer Registration</h2>
        <a href="customers.php?section=list" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Customer List
        </a>
    </div>
    <div class="cust-card-body">
        <form method="POST" action="customers.php?section=add" enctype="multipart/form-data">
            <input type="hidden" name="action" value="encode_customer">

            <div class="cust-form-grid" style="margin-bottom:14px;">
                <!-- First Name -->
                <div>
                    <label class="cust-label">First Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="first_name" class="cust-input" placeholder="First Name"
                           required maxlength="100" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                </div>

                <!-- Last Name -->
                <div>
                    <label class="cust-label">Last Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="last_name" class="cust-input" placeholder="Last Name"
                           required maxlength="100" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                </div>

                <!-- Contact Number -->
                <div>
                    <label class="cust-label">Contact Number</label>
                    <input type="text" name="contact" class="cust-input" placeholder="e.g. 09XX-XXX-XXXX"
                           maxlength="50" value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>">
                </div>

                <!-- Address -->
                <div>
                    <label class="cust-label">Address</label>
                    <input type="text" name="address" class="cust-input" placeholder="Complete address"
                           maxlength="255" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                </div>

                <!-- Government ID Type -->
                <div>
                    <label class="cust-label">Government ID Type</label>
                    <select name="id_type" class="cust-input">
                        <option value="">— Select ID type —</option>
                        <?php foreach ($gov_id_types as $gid): ?>
                        <option value="<?= htmlspecialchars($gid) ?>"
                            <?= ($_POST['id_type'] ?? '') === $gid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($gid) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ID Image Upload -->
                <div>
                    <label class="cust-label">Government ID Image <span style="color:#6c757d;font-weight:400;">(optional)</span></label>
                    <input type="file" name="id_image" class="cust-input" accept="image/*,.pdf"
                           style="padding:6px 10px;">
                    <small style="color:#6c757d;font-size:11px;">JPG, PNG, PDF — max 5MB</small>
                </div>

                <!-- CR Image Upload -->
                <div style="grid-column: span 2;">
                    <label class="cust-label">Certificate of Registration (CR) <span style="color:#6c757d;font-weight:400;">(optional)</span></label>
                    <input type="file" name="cr_image" class="cust-input" accept="image/*,.pdf"
                           style="padding:6px 10px;">
                    <small style="color:#6c757d;font-size:11px;">JPG, PNG, PDF — max 5MB</small>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;padding-top:8px;border-top:1px solid #f0f0f0;margin-top:4px;">
                <button type="submit" class="cust-btn cust-btn-primary">
                    <i class="fas fa-save"></i> Save Customer
                </button>
                <a href="customers.php?section=list" class="cust-btn"
                   style="background:#f1f5f9;color:#475569;text-decoration:none;">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ══ SECTION: EDIT CUSTOMER PROFILE ════════════════════════════════════════ -->
<?php elseif ($section === 'edit' && $edit_customer): ?>
<?php
    $name_parts = explode(' ', $edit_customer['name'], 2);
    $fname = $name_parts[0] ?? '';
    $lname = $name_parts[1] ?? '';
?>
<div class="cust-card">
    <div class="cust-card-head">
        <h2 class="cust-card-title"><i class="fas fa-user-edit"></i> Edit Customer General Info</h2>
        <a href="customers.php?section=list" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Customer List
        </a>
    </div>
    <div class="cust-card-body">
        <form method="POST" action="customers.php?section=edit" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_customer">
            <input type="hidden" name="customer_id" value="<?= (int)$edit_customer['id'] ?>">

            <div class="cust-form-grid" style="margin-bottom:14px;">
                <!-- First Name -->
                <div>
                    <label class="cust-label">First Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="first_name" class="cust-input" required maxlength="100" value="<?= htmlspecialchars($fname) ?>">
                </div>

                <!-- Last Name -->
                <div>
                    <label class="cust-label">Last Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="last_name" class="cust-input" required maxlength="100" value="<?= htmlspecialchars($lname) ?>">
                </div>

                <!-- Contact Number -->
                <div>
                    <label class="cust-label">Contact Number</label>
                    <input type="text" name="contact" class="cust-input" maxlength="50" value="<?= htmlspecialchars($edit_customer['contact_number'] ?? '') ?>">
                </div>

                <!-- Address -->
                <div>
                    <label class="cust-label">Address</label>
                    <input type="text" name="address" class="cust-input" maxlength="255" value="<?= htmlspecialchars($edit_customer['address'] ?? '') ?>">
                </div>

                <!-- Status -->
                <div>
                    <label class="cust-label">Account Status</label>
                    <select name="status" class="cust-input">
                        <option value="active" <?= ($edit_customer['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($edit_customer['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin:14px 0;font-size:12px;color:#1e40af;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-info-circle"></i>
                <span>Credit line, suki status, and other confidential fields are protected and can only be updated by a <strong>Manager</strong>.</span>
            </div>

            <div style="display:flex;gap:10px;align-items:center;padding-top:8px;border-top:1px solid #f0f0f0;margin-top:4px;">
                <button type="submit" class="cust-btn cust-btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="customers.php?section=list" class="cust-btn"
                   style="background:#f1f5f9;color:#475569;text-decoration:none;">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ══ SECTION: CUSTOMER LIST ════════════════════════════════════════════════ -->
<?php elseif ($section === 'list'): ?>

<div class="cust-card">
    <div class="cust-card-head">
        <h2 class="cust-card-title"><i class="fas fa-list"></i> Customer List</h2>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-size:13px;color:#6c757d;"><?= count($customers) ?> customer<?= count($customers) !== 1 ? 's' : '' ?></span>
            <a href="customers.php?section=add" class="cust-btn cust-btn-primary" style="font-size:12px;padding:7px 14px;text-decoration:none;">
                <i class="fas fa-user-plus"></i> Add Customer
            </a>
            <?php if (!empty($customers)): ?>
            <a href="customers.php?section=list&export=csv" class="cust-btn" style="background:#28a745;color:#fff;font-size:12px;padding:7px 14px;text-decoration:none;">
                <i class="fas fa-file-csv"></i> CSV
            </a>
            <a href="customers.php?section=list&export=excel" class="cust-btn" style="background:#1f7a3e;color:#fff;font-size:12px;padding:7px 14px;text-decoration:none;">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button onclick="printStaffListPDF()" class="cust-btn" style="background:#dc3545;color:#fff;border:none;cursor:pointer;font-size:12px;padding:7px 14px;">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Cards -->
    <div style="display:flex;gap:12px;padding:14px 18px;background:#f9fafb;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;">
        <div style="flex:1;min-width:120px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;">
            <div style="font-size:20px;font-weight:800;color:#002F70;"><?= $summary_total ?></div>
            <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Customers</div>
        </div>
        <div style="flex:1;min-width:120px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #28a745;border-radius:6px;padding:10px 14px;">
            <div style="font-size:20px;font-weight:800;color:#28a745;"><?= $summary_active ?></div>
            <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Active</div>
        </div>
        <div style="flex:1;min-width:120px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #6c757d;border-radius:6px;padding:10px 14px;">
            <div style="font-size:20px;font-weight:800;color:#6c757d;"><?= $summary_inactive ?></div>
            <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Inactive</div>
        </div>
        <?php if ($summary_locked > 0): ?>
        <div style="flex:1;min-width:120px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #7c3aed;border-radius:6px;padding:10px 14px;">
            <div style="font-size:20px;font-weight:800;color:#7c3aed;"><?= $summary_locked ?></div>
            <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Locked</div>
        </div>
        <?php endif; ?>
    </div>
    <div class="cust-card-body">
        <input type="text" class="cust-search" id="encodeSearch" placeholder="&#128269; Search by name..." oninput="filterTable('encodeSearch','encodeTable')">
        <div style="overflow-x:hidden;">
            <table class="cust-table" id="encodeTable">
                <thead><tr>
                    <th>#</th><th>Name</th><th>Contact</th><th>ID Type</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            No customers yet.
                            <br><a href="customers.php?section=add" style="color:#002F70;font-weight:700;font-size:13px;margin-top:8px;display:inline-block;">
                                <i class="fas fa-user-plus"></i> Add the first customer
                            </a>
                        </div>
                    </td></tr>
                <?php else: foreach ($customers as $c): ?>
                    <tr data-search="<?= strtolower(htmlspecialchars($c['name'])) ?>">
                        <td style="color:#9ca3af;font-size:11px;">#<?= (int)$c['id'] ?></td>
                        <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($c['contact_number'] ?? '—') ?></td>
                        <td style="font-size:12px;color:#6c757d;"><?= htmlspecialchars($c['id_type'] ?? '—') ?></td>
                        <td><span class="badge-<?= $c['status']==='active'?'active':'inactive' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <a href="customers.php?section=edit&customer_id=<?= (int)$c['id'] ?>"
                                   class="edit-link" style="border-color:#2563eb;color:#2563eb;" title="Edit General Info">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="customers.php?section=history&cust_id=<?= (int)$c['id'] ?>"
                                   class="edit-link" title="View history">
                                    <i class="fas fa-history"></i> History
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ══ SECTION: TRANSACTION LINKAGE ══════════════════════════════════════════ -->
<?php elseif ($section === 'linkage'): ?>
<div class="cust-card">

    <div class="cust-card-body">
        <form method="GET" action="customers.php" style="margin-bottom:20px;">
            <input type="hidden" name="section" value="linkage">
            <label class="cust-label">Select Customer</label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <select name="customer_id" class="cust-input" style="max-width:340px;">
                    <option value="">— Select a customer —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $linkage_customer_id==$c['id']?'selected':'' ?>>
                        #<?= (int)$c['id'] ?> — <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="cust-btn cust-btn-primary"><i class="fas fa-search"></i> View Transactions</button>
            </div>
        </form>

        <?php if ($linkage_customer_id): ?>
            <?php
            // Show selected customer info
            $sel_cust = null;
            foreach ($customers as $c) { if ($c['id'] == $linkage_customer_id) { $sel_cust = $c; break; } }
            if ($sel_cust): ?>
            <div style="background:#f0f4ff;border:1px solid #c7d7f9;border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;">
                <strong style="color:#002F70;"><?= htmlspecialchars($sel_cust['name']) ?></strong>
                <span style="color:#6c757d;margin-left:12px;">Contact: <?= htmlspecialchars($sel_cust['contact_number'] ?? '—') ?></span>
            </div>
            <?php endif; ?>

            <div style="border-bottom:1px solid #dee2e6; margin-bottom:20px; display:flex; gap:10px;">
                <button type="button" class="cust-tab active" onclick="switchLinkageTab('jo', this)"><i class="fas fa-wrench"></i> Job Orders</button>
                <button type="button" class="cust-tab" onclick="switchLinkageTab('merch', this)"><i class="fas fa-shopping-cart"></i> Merchandise Transactions</button>
            </div>

            <!-- TAB CONTENT: JOB ORDERS -->
            <div id="tab-jo" class="linkage-tab-content">
                <?php if (empty($job_orders_linked)): ?>
                    <div class="empty-state"><i class="fas fa-wrench"></i>No job orders found for this customer.</div>
                <?php else: ?>
                <div style="overflow-x:hidden;margin-bottom:24px;">
                    <table class="cust-table">
                        <thead><tr><th>JO Ref</th><th>Service</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($job_orders_linked as $jo): ?>
                            <tr>
                                <td><strong style="color:#002F70;"><?= htmlspecialchars($jo['jo_ref']) ?></strong></td>
                                <td><?= htmlspecialchars($jo['service']) ?></td>
                                <td style="font-size:12px;color:#6c757d;"><?= date('M d, Y', strtotime($jo['created_at'])) ?></td>
                                <td>
                                    <?php $st = strtolower($jo['status']);
                                    $cls = $st==='completed'?'badge-completed':($st==='rejected'?'badge-inactive':'badge-pending'); ?>
                                    <span class="<?= $cls ?>"><?= htmlspecialchars($jo['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- TAB CONTENT: MERCHANDISE -->
            <div id="tab-merch" class="linkage-tab-content" style="display:none;">
                <?php if (empty($merch_linked)): ?>
                    <div class="empty-state"><i class="fas fa-shopping-cart"></i>No merchandise transactions found for this customer.</div>
                <?php else: ?>
                <div style="overflow-x:hidden;">
                    <table class="cust-table">
                        <thead><tr><th>#</th><th>Customer</th><th>Item / Product</th><th>Qty</th><th>Amount</th><th>Payment</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($merch_linked as $mt): ?>
                            <tr>
                                <td style="color:#6c757d;font-size:12px;">#<?= (int)$mt['id'] ?></td>
                                <td><?= htmlspecialchars($mt['customer_name'] ?? '—') ?></td>
                                <td><strong style="color:#002F70;"><?= htmlspecialchars($mt['item_sku'] ?? '—') ?></strong></td>
                                <td><?= htmlspecialchars($mt['quantity'] ?? '1') ?></td>
                                <td><strong style="color:#065f46;">₱<?= number_format((float)$mt['total_amount'], 2) ?></strong></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($mt['payment_method'] ?? '—') ?></td>
                                <td style="font-size:12px;color:#6c757d;"><?= date('M d, Y', strtotime($mt['txn_date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-hand-pointer"></i>Select a customer above to view their linked transactions.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ SECTION: CUSTOMER HISTORY ════════════════════════════════════════════ -->
<?php elseif ($section === 'history'): ?>

<style>
/* ── Customer History — Clean Staff Design ─────────────────────────────── */

/* Filter panel */
.ch-filter-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 18px 20px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

.ch-filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.ch-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ch-field label {
    font-size: 11px;
    font-weight: 600;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.ch-field select,
.ch-field input[type="date"] {
    padding: 9px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    color: #1f2937;
    background: #fff;
    height: 38px;
    box-sizing: border-box;
    transition: all 0.15s;
}

.ch-field select:focus,
.ch-field input[type="date"]:focus {
    border-color: var(--staff-blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,47,112,.08);
}

.ch-field-customer { flex: 1; min-width: 220px; max-width: 340px; }
.ch-field-type,
.ch-field-status,
.ch-field-date { min-width: 140px; }

.ch-filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.ch-btn-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    background: var(--staff-blue);
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    height: 38px;
    white-space: nowrap;
    transition: all 0.15s;
}

.ch-btn-filter:hover {
    background: #001f4d;
    transform: translateY(-1px);
}

.ch-btn-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
}

.ch-btn-clear:hover {
    background: #e5e7eb;
    color: #374151;
}

/* Customer info header — Clean minimal style */
.ch-customer-header {
    display: flex;
    align-items: center;
    gap: 0;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px 18px;
    margin-bottom: 16px;
    font-size: 13px;
    flex-wrap: wrap;
}

.ch-cust-name {
    font-weight: 700;
    color: var(--staff-blue);
    font-size: 14px;
    margin-right: 8px;
}

.ch-cust-sep {
    color: #d1d5db;
    margin: 0 12px;
    font-size: 14px;
    font-weight: 300;
}

.ch-cust-item {
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}

.ch-cust-item-label {
    color: #6b7280;
    font-size: 12px;
}

.ch-cust-item-value {
    font-weight: 600;
    color: #1f2937;
    font-size: 13px;
}

.ch-cust-item-value.danger  { color: #dc2626; }
.ch-cust-item-value.success { color: #16a34a; }
.ch-cust-item-value.neutral { color: #374151; }

/* Table card */
.ch-table-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    overflow: hidden;
}

.ch-table-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
}

.ch-table-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--staff-blue);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.ch-record-count {
    font-size: 12px;
    color: #9ca3af;
    font-weight: 500;
}

.ch-table-wrap {
    overflow-x: auto;
    max-width: 100%;
}

.ch-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.ch-table th {
    background: var(--staff-blue);
    color: #fff;
    padding: 11px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
    white-space: nowrap;
}

.ch-table td {
    padding: 11px 14px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
    color: #374151;
    background: #fff;
}

.ch-table tbody tr:last-child td {
    border-bottom: none;
}

.ch-table tbody tr:hover td {
    background: var(--staff-blue-hover);
}

/* Badges — Clean staff style */
.ch-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.ch-badge-fuel {
    background: #fff7ed;
    color: #c2410c;
    border: 1px solid #fed7aa;
}

.ch-badge-jo {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.ch-badge-merch {
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
}

.ch-badge-paid {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #d1fae5;
}

.ch-badge-unpaid {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fee2e2;
}

.ch-badge-partial {
    background: #fffbeb;
    color: #92400e;
    border: 1px solid #fef3c7;
}

/* Empty states */
.ch-empty-row td {
    text-align: center;
    padding: 48px 20px;
    color: #9ca3af;
    font-size: 13px;
    border-bottom: none !important;
}

.ch-prompt {
    text-align: center;
    padding: 64px 20px;
    color: #9ca3af;
}

.ch-prompt i {
    font-size: 3rem;
    display: block;
    margin-bottom: 12px;
    color: #d1d5db;
}

.ch-prompt p {
    font-size: 13px;
    margin: 0;
}

@media (max-width: 768px) {
    .ch-filter-row {
        flex-direction: column;
    }
    .ch-field-customer,
    .ch-field-type,
    .ch-field-status,
    .ch-field-date {
        min-width: 100%;
        max-width: 100%;
    }
    .ch-customer-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .ch-cust-sep {
        display: none;
    }
}
</style>

<?php
/* ── Pre-compute customer info values ─────────────────────────────────────── */
$ci_balance   = 0; $ci_limit = 0; $ci_remaining = 0;
$ci_total_txns = 0; $ci_unpaid_count = 0;
if ($hist_customer_info) {
    $ci_balance      = (float)($hist_customer_info['balance'] ?? 0);
    $ci_limit        = (float)($hist_customer_info['credit_limit'] ?? 0);
    $ci_remaining    = $ci_limit - $ci_balance;
    $ci_total_txns   = count($hist_records);
    $ci_unpaid_count = count(array_filter($hist_records, fn($r) => $r['payment_status'] === 'Unpaid'));
}
?>

<!-- ── FILTER PANEL ──────────────────────────────────────────────────────── -->
<div class="ch-filter-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <a href="customers.php?section=list" class="btn-back" style="margin:0;">
            <i class="fas fa-arrow-left"></i> Back to Customer List
        </a>
        <?php if ($hist_selected_id && !empty($hist_records)): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="customers.php?section=history&cust_id=<?= $hist_selected_id ?>&export=csv<?= $hist_filter_date_from ? '&hist_date_from='.$hist_filter_date_from : '' ?><?= $hist_filter_date_to ? '&hist_date_to='.$hist_filter_date_to : '' ?>"
               style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#28a745;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">
                <i class="fas fa-file-csv"></i> CSV
            </a>
            <a href="customers.php?section=history&cust_id=<?= $hist_selected_id ?>&export=excel<?= $hist_filter_date_from ? '&hist_date_from='.$hist_filter_date_from : '' ?><?= $hist_filter_date_to ? '&hist_date_to='.$hist_filter_date_to : '' ?>"
               style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#1f7a3e;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button onclick="printHistoryPDF()" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#dc3545;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
        <?php endif; ?>
    </div>
    <form method="GET" action="customers.php" id="chFilterForm">
        <input type="hidden" name="section" value="history">
        <div class="ch-filter-row">

            <!-- Select Customer -->
            <div class="ch-field ch-field-customer">
                <label>Select Customer</label>
                <select name="cust_id" id="chCustSelect">
                    <option value="">— Choose a customer —</option>
                    <?php foreach ($hist_customers as $hc): ?>
                    <option value="<?= (int)$hc['id'] ?>"
                        <?= $hist_selected_id === (int)$hc['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($hc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Type -->
            <div class="ch-field ch-field-type">
                <label>Type</label>
                <select name="hist_type">
                    <option value=""           <?= $hist_filter_type === ''            ? 'selected' : '' ?>>All Types</option>
                    <option value="fuel"       <?= $hist_filter_type === 'fuel'        ? 'selected' : '' ?>>Fuel</option>
                    <option value="merchandise"<?= $hist_filter_type === 'merchandise' ? 'selected' : '' ?>>Merchandise</option>
                    <option value="job_order"  <?= $hist_filter_type === 'job_order'   ? 'selected' : '' ?>>Job Order</option>
                </select>
            </div>

            <!-- Payment Status -->
            <div class="ch-field ch-field-status">
                <label>Payment Status</label>
                <select name="hist_status">
                    <option value=""        <?= $hist_filter_status === ''        ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Paid"    <?= $hist_filter_status === 'Paid'    ? 'selected' : '' ?>>Paid</option>
                    <option value="Unpaid"  <?= $hist_filter_status === 'Unpaid'  ? 'selected' : '' ?>>Unpaid</option>
                    <option value="Partial" <?= $hist_filter_status === 'Partial' ? 'selected' : '' ?>>Partial</option>
                </select>
            </div>

            <!-- Date Range: From -->
            <div class="ch-field ch-field-date">
                <label>From</label>
                <input type="date" name="hist_date_from"
                       value="<?= htmlspecialchars($_GET['hist_date_from'] ?? $hist_filter_date) ?>">
            </div>

            <!-- Date Range: To -->
            <div class="ch-field ch-field-date">
                <label>To</label>
                <input type="date" name="hist_date_to"
                       value="<?= htmlspecialchars($_GET['hist_date_to'] ?? '') ?>">
            </div>

            <!-- Actions -->
            <div class="ch-filter-actions">
                <button type="submit" class="ch-btn-filter">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <?php if ($hist_selected_id): ?>
                <a href="customers.php?section=history&cust_id=<?= $hist_selected_id ?>"
                   class="ch-btn-clear" title="Clear filters">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>

        </div>
    </form>
</div>

<?php if (!$hist_selected_id): ?>
<!-- ── No customer selected ─────────────────────────────────────────────── -->
<div class="ch-table-card">
    <div class="ch-prompt">
        <i class="fas fa-user-clock"></i>
        <p>Select a customer above to view their transaction history.</p>
    </div>
</div>

<?php else: ?>

<?php if ($hist_customer_info): ?>
<!-- ── Customer Info Inline Header ──────────────────────────────────────── -->
<div class="ch-customer-header">
    <span class="ch-cust-name"><?= htmlspecialchars($hist_customer_info['name']) ?></span>
    <span class="ch-cust-sep">|</span>
    <span class="ch-cust-item">
        <span class="ch-cust-item-label">Status</span>
        <span class="ch-cust-item-value <?= strtolower($hist_customer_info['status'] ?? 'active') === 'active' ? 'success' : 'danger' ?>">
            <?= ucfirst(htmlspecialchars($hist_customer_info['status'] ?? 'active')) ?>
        </span>
    </span>
    <span class="ch-cust-sep">|</span>
    <span class="ch-cust-item">
        <span class="ch-cust-item-label">Transactions:</span>
        <span class="ch-cust-item-value neutral"><?= $ci_total_txns ?></span>
    </span>
</div>

<!-- ── History Summary Cards ─────────────────────────────────────────────── -->
<?php if (!empty($hist_records)):
    $hist_total_amount = array_sum(array_column($hist_records, 'total_amount'));
    $hist_paid_count   = count(array_filter($hist_records, fn($r) => $r['payment_status'] === 'Paid'));
    $hist_unpaid_count = count(array_filter($hist_records, fn($r) => $r['payment_status'] === 'Unpaid'));
?>
<div id="histSummaryCards" style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
    <div style="flex:1;min-width:130px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;">
        <div style="font-size:20px;font-weight:800;color:#002F70;"><?= $ci_total_txns ?></div>
        <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Records</div>
    </div>
    <div style="flex:1;min-width:130px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #dc3545;border-radius:6px;padding:10px 14px;">
        <div style="font-size:20px;font-weight:800;color:#dc3545;">₱<?= number_format($hist_total_amount, 2) ?></div>
        <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Amount</div>
    </div>
    <div style="flex:1;min-width:130px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #28a745;border-radius:6px;padding:10px 14px;">
        <div style="font-size:20px;font-weight:800;color:#28a745;"><?= $hist_paid_count ?></div>
        <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Paid</div>
    </div>
    <div style="flex:1;min-width:130px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #fd7e14;border-radius:6px;padding:10px 14px;">
        <div style="font-size:20px;font-weight:800;color:#fd7e14;"><?= $hist_unpaid_count ?></div>
        <div style="font-size:11px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.5px;">Unpaid</div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Transaction Table ────────────────────────────────────────────────── -->
<div class="ch-table-card">
    <div class="ch-table-head">
        <h3 class="ch-table-title">
            <i class="fas fa-receipt"></i> Transaction History
        </h3>
        <span class="ch-record-count">
            <?= $ci_total_txns ?> record<?= $ci_total_txns !== 1 ? 's' : '' ?>
        </span>
    </div>
    <div class="ch-table-wrap">
        <table class="ch-table">
            <thead>
                <tr>
                    <th>Transaction Date</th>
                    <th>Type</th>
                    <th>Reference No.</th>
                    <th>Amount</th>
                    <th>Payment Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($hist_records)): ?>
                <tr class="ch-empty-row">
                    <td colspan="6">No records found for this customer.</td>
                </tr>
            <?php else:
                foreach ($hist_records as $hr):
                    $ps = $hr['payment_status'];
                    $ps_class = match($ps) {
                        'Paid'    => 'ch-badge-paid',
                        'Partial' => 'ch-badge-partial',
                        default   => 'ch-badge-unpaid',
                    };
                    $ps_icon = match($ps) {
                        'Paid'    => 'fa-check-circle',
                        'Partial' => 'fa-adjust',
                        default   => 'fa-clock',
                    };
                    $rec_type   = $hr['record_type'] ?? 'merchandise';
                    $type_class = match($rec_type) {
                        'job_order' => 'ch-badge-jo',
                        'fuel'      => 'ch-badge-fuel',
                        default     => 'ch-badge-merch',
                    };
                    $type_icon  = match($rec_type) {
                        'job_order' => 'fa-tools',
                        'fuel'      => 'fa-gas-pump',
                        default     => 'fa-shopping-cart',
                    };
                    $type_label = match($rec_type) {
                        'job_order' => 'Job Order',
                        'fuel'      => 'Fuel',
                        default     => 'Merchandise',
                    };
                    $remarks = '';
                    if ($rec_type === 'job_order') {
                        $remarks = $hr['service_label'] ?: '—';
                        if (!empty($hr['vehicle_plate'])) $remarks .= ' · ' . $hr['vehicle_plate'];
                    } else {
                        $remarks = $hr['merch_items_summary'] ?: ($hr['service_label'] ?: '—');
                    }
            ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <?= date('M j, Y', strtotime($hr['txn_date'])) ?>
                        <span style="display:block;font-size:11px;color:#9ca3af;">
                            <?= date('h:i A', strtotime($hr['txn_date'])) ?>
                        </span>
                    </td>
                    <td>
                        <span class="ch-badge <?= $type_class ?>">
                            <i class="fas <?= $type_icon ?>"></i> <?= $type_label ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-family:monospace;font-size:12px;font-weight:700;color:#002F70;">
                            <?= htmlspecialchars($hr['ref_number']) ?>
                        </span>
                    </td>
                    <td style="font-weight:700;color:#002F70;white-space:nowrap;">
                        ₱<?= number_format((float)$hr['total_amount'], 2) ?>
                    </td>
                    <td>
                        <span class="ch-badge <?= $ps_class ?>">
                            <i class="fas <?= $ps_icon ?>"></i> <?= $ps ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#6c757d;max-width:240px;">
                        <?= htmlspecialchars($remarks) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($hist_records)): ?>
    <div style="display:flex;justify-content:flex-end;align-items:center;padding:10px 18px;border-top:1px solid #f0f0f0;gap:20px;font-size:13px;">
        <span style="color:#6c757d;"><?= $ci_total_txns ?> record<?= $ci_total_txns !== 1 ? 's' : '' ?></span>
        <span style="font-weight:700;color:#002F70;">
            Total: ₱<?= number_format(array_sum(array_column($hist_records, 'total_amount')), 2) ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<?php endif; // end $hist_selected_id ?>

<!-- ══ SECTION: OUTSTANDING BALANCES ════════════════════════════════════════ -->
<?php elseif ($section === 'balances'): ?>
<?php /* removed — staff no longer has access to outstanding balances */ ?>
<?php endif; ?>

<script>
function filterTable(inputId, tableId) {
    const q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr[data-search]').forEach(function(row) {
        row.style.display = row.getAttribute('data-search').includes(q) ? '' : 'none';
    });
}

function switchLinkageTab(tabId, btn) {
    document.querySelectorAll('.linkage-tab-content').forEach(function(el) {
        el.style.display = 'none';
    });
    document.querySelectorAll('.cust-tab').forEach(function(el) {
        el.classList.remove('active');
    });
    document.getElementById('tab-' + tabId).style.display = 'block';
    if(btn) btn.classList.add('active');
}

// Auto-submit history form when customer dropdown changes
const chCustSelect = document.getElementById('chCustSelect');
if (chCustSelect) {
    chCustSelect.addEventListener('change', function() {
        if (this.value) document.getElementById('chFilterForm').submit();
    });
}

// PDF Print for History Section
function printHistoryPDF() {
    const printContent = document.getElementById('histSummaryCards') ? 
        (document.getElementById('histSummaryCards').outerHTML || '') : '';
    const tableEl = document.querySelector('.ch-table-card');
    if (!tableEl) { alert('No data to print.'); return; }
    const custName = document.querySelector('.ch-cust-name') ? document.querySelector('.ch-cust-name').innerText : 'Customer';
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html>
<html><head>
<title>Customer History - ${custName}</title>
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
<div class="meta">Customer: <strong>${custName}</strong> &nbsp;|&nbsp; Printed: ${new Date().toLocaleString()}</div>
${printContent}
${tableEl.outerHTML}
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
}

// PDF Print for Customer List Section
function printStaffListPDF() {
    const kpiEl = document.querySelector('.cust-card .cust-card-head');
    const tableEl = document.getElementById('encodeTable');
    if (!tableEl) { alert('No customer data to print.'); return; }
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html>
<html><head>
<title>Staff Customer Directory</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; color: #333; }
  h2 { color: #002F6C; margin-bottom: 4px; }
  .meta { color: #666; font-size: 11px; margin-bottom: 16px; }
  .kpis { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
  .kpi { flex: 1; min-width: 100px; border: 1px solid #ddd; border-left: 4px solid #002F6C; padding: 8px 12px; border-radius: 4px; background: #fcfcfc; }
  .kpi-val { font-size: 18px; font-weight: 800; color: #002F6C; }
  .kpi-lbl { font-size: 10px; text-transform: uppercase; color: #888; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #002F6C; color: #fff; padding: 8px; text-align: left; }
  td { padding: 7px 8px; border-bottom: 1px solid #eee; }
  .badge-active { background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; }
  .badge-inactive { background: #f3f4f6; color: #374151; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; }
  @media print { body { margin: 0; } }
</style>
</head><body>
<h2>Staff Customer Directory</h2>
<div class="meta">Printed on: \${new Date().toLocaleString()}</div>
<table style="width:100%;border-collapse:collapse;font-size:11px;">\${tableEl.innerHTML}</table>
<script>window.onload=function(){window.print();}<\/script>
</body></html>`);
    w.document.close();
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
