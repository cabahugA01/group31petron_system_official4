<?php
$page_id = 'customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php'); exit;
}

// ── Resolve active section (driven by sidebar sub-menu) ───────────────────────
$valid_sections = ['encode', 'history', 'linkage'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'encode';

// Block any direct attempt to access removed sections
if (isset($_GET['section']) && in_array($_GET['section'], ['update', 'balances'])) {
    header('Location: customers.php?section=encode'); exit;
}

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
    $name    = trim($_POST['name']    ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $id_type = trim($_POST['id_type'] ?? '');
    $credit  = (float)($_POST['credit_limit'] ?? 0);

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
        $flash_error = 'Customer name is required.';
    } else {
        try {
            $ins_cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
            $col_list = ['name', 'station_id', 'status', 'created_at'];
            $val_list = [$name, $station_id, 'active', date('Y-m-d H:i:s')];
            if (in_array('contact_number', $ins_cols)) { $col_list[] = 'contact_number'; $val_list[] = $contact; }
            if (in_array('id_type',        $ins_cols)) { $col_list[] = 'id_type';        $val_list[] = $id_type; }
            if (in_array('id_image',       $ins_cols)) { $col_list[] = 'id_image';       $val_list[] = $id_image_path; }
            if (in_array('cr_image',       $ins_cols)) { $col_list[] = 'cr_image';       $val_list[] = $cr_image_path; }
            if (in_array('credit_limit',   $ins_cols)) { $col_list[] = 'credit_limit';   $val_list[] = $credit; }
            if (in_array('balance',        $ins_cols)) { $col_list[] = 'balance';         $val_list[] = 0; }
            $placeholders = implode(',', array_fill(0, count($col_list), '?'));
            $pdo->prepare("INSERT INTO customers (" . implode(',', $col_list) . ") VALUES ($placeholders)")
                ->execute($val_list);
            $flash_success = "Customer \"$name\" encoded successfully.";
        } catch (Exception $e) {
            $flash_error = 'Error saving customer: ' . $e->getMessage();
        }
    }
}
// ── Data fetches ──────────────────────────────────────────────────────────────
$customers = [];
try {
    // Detect available columns to avoid errors on older schemas
    $avail = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $sel_contact  = in_array('contact_number', $avail) ? 'contact_number' : "'' AS contact_number";
    $sel_id_type  = in_array('id_type',        $avail) ? 'id_type'        : "'' AS id_type";
    $sel_id_image = in_array('id_image',       $avail) ? 'id_image'       : "'' AS id_image";
    $sel_cr_image = in_array('cr_image',       $avail) ? 'cr_image'       : "'' AS cr_image";
    $sel_balance  = in_array('balance',        $avail) ? 'balance'        : (in_array('current_balance', $avail) ? 'current_balance AS balance' : "0 AS balance");
    $sel_credit   = in_array('credit_limit',   $avail) ? 'credit_limit'   : "0 AS credit_limit";
    $sel_status   = in_array('status',         $avail) ? 'status'         : "'active' AS status";
    $s = $pdo->prepare("SELECT id, name, $sel_contact, $sel_id_type, $sel_id_image, $sel_cr_image, $sel_credit, $sel_balance, $sel_status FROM customers WHERE station_id=? ORDER BY name");
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
$hist_filter_date    = $_GET['hist_date']   ?? '';
$hist_records        = [];
$hist_customer_info  = null;
if ($section === 'history') {
    try {
        // All customers for this station (for the selector)
        $s = $pdo->prepare("SELECT id, name, balance, credit_limit, status FROM customers WHERE station_id=? ORDER BY name ASC");
        $s->execute([$station_id]);
        $hist_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if ($hist_selected_id) {
        // Load selected customer info
        foreach ($hist_customers as $hc) {
            if ($hc['id'] === $hist_selected_id) { $hist_customer_info = $hc; break; }
        }

        // ── Build unified history from job_orders + merchandise_transactions ──
        try {
            $jo_cols  = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
            $mt_cols  = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
            
            $hist_customer_name = $hist_customer_info['name'] ?? '';

            $has_jo_credit  = in_array('credit_customer_id', $jo_cols);
            $has_jo_name    = in_array('customer_name', $jo_cols);
            
            $has_mt_cid     = in_array('customer_id', $mt_cols);
            $has_mt_credit  = in_array('credit_customer_id', $mt_cols);
            $has_mt_name    = in_array('customer_name', $mt_cols);
            
            $jo_num_col     = in_array('job_order_number', $jo_cols) ? 'job_order_number'
                            : (in_array('job_order_id', $jo_cols) ? 'job_order_id' : 'NULL');
            $jo_svc_col     = in_array('service_type', $jo_cols) ? 'service_type'
                            : (in_array('service_description', $jo_cols) ? 'service_description' : "''");
            $jo_pay_stat    = in_array('payment_status', $jo_cols) ? 'payment_status' : "''";
            $jo_amt_paid    = in_array('amount_paid', $jo_cols) ? 'amount_paid' : '0';
            $jo_total       = in_array('actual_cost', $jo_cols) ? 'COALESCE(actual_cost, estimated_cost, 0)'
                            : (in_array('estimated_cost', $jo_cols) ? 'COALESCE(estimated_cost, 0)' : '0');
            $jo_plate       = in_array('vehicle_plate', $jo_cols) ? 'vehicle_plate' : "''";
            
            $jo_cust_cond   = $has_jo_credit
                ? '(jo.customer_id=? OR jo.credit_customer_id=?' . ($has_jo_name && $hist_customer_name ? ' OR jo.customer_name=?' : '') . ')'
                : '(jo.customer_id=?' . ($has_jo_name && $hist_customer_name ? ' OR jo.customer_name=?' : '') . ')';
                
            $jo_params      = $has_jo_credit
                ? [$station_id, $hist_selected_id, $hist_selected_id]
                : [$station_id, $hist_selected_id];
                
            if ($has_jo_name && $hist_customer_name) {
                $jo_params[] = $hist_customer_name;
            }

            $mt_date_col    = in_array('transaction_date', $mt_cols) ? 'COALESCE(mt.transaction_date, mt.created_at)' : 'mt.created_at';
            
            // Build MT conditions
            $mt_cond_parts = [];
            $mt_params = [$station_id];
            
            if ($has_mt_cid) {
                $mt_cond_parts[] = "mt.customer_id=?";
                $mt_params[] = $hist_selected_id;
            }
            if ($has_mt_credit) {
                $mt_cond_parts[] = "mt.credit_customer_id=?";
                $mt_params[] = $hist_selected_id;
            }
            if ($has_mt_name && $hist_customer_name) {
                $mt_cond_parts[] = "mt.customer_name=?";
                $mt_params[] = $hist_customer_name;
            }
            
            $mt_cust_cond = empty($mt_cond_parts) ? '1=0' : '(' . implode(' OR ', $mt_cond_parts) . ')';

            // Date filter
            $jo_date_filter = $mt_date_filter = '';
            if ($hist_filter_date) {
                $jo_date_filter  = " AND DATE(jo.created_at) = " . $pdo->quote($hist_filter_date);
                $mt_date_filter  = " AND DATE($mt_date_col) = " . $pdo->quote($hist_filter_date);
            }

            // ── Job Orders ──
            $jo_rows = [];
            if ($hist_filter_type === '' || $hist_filter_type === 'job_order') {
                $jo_sql = "
                    SELECT
                        'job_order' AS record_type,
                        jo.id,
                        COALESCE($jo_num_col, CONCAT('JO-', jo.id)) AS ref_number,
                        COALESCE($jo_svc_col, '—') AS service_label,
                        '' AS merch_items_summary,
                        $jo_total AS total_amount,
                        $jo_amt_paid AS amount_paid,
                        COALESCE($jo_pay_stat, 'Unpaid') AS payment_status,
                        jo.payment_method,
                        jo.status AS txn_status,
                        jo.created_at AS txn_date,
                        $jo_plate AS vehicle_plate
                    FROM job_orders jo
                    WHERE jo.station_id=? AND $jo_cust_cond
                    $jo_date_filter
                    ORDER BY jo.created_at DESC
                ";
                $st = $pdo->prepare($jo_sql);
                $st->execute($jo_params);
                $jo_rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }

            // ── Merchandise Transactions ──
            $mt_rows = [];
            if (($hist_filter_type === '' || $hist_filter_type === 'merchandise') && ($has_mt_cid || $has_mt_credit)) {
                $mt_sql = "
                    SELECT
                        'merchandise' AS record_type,
                        mt.id,
                        COALESCE(mt.transaction_id, CONCAT('MT-', mt.id)) AS ref_number,
                        COALESCE(mt.job_order_service, '') AS service_label,
                        '' AS merch_items_summary,
                        COALESCE(mt.total_amount, 0) AS total_amount,
                        COALESCE(mt.total_amount, 0) AS amount_paid,
                        COALESCE(mt.status, 'Unpaid') AS payment_status,
                        mt.payment_method,
                        COALESCE(mt.validation_status, mt.status, 'Pending') AS txn_status,
                        $mt_date_col AS txn_date,
                        '' AS vehicle_plate
                    FROM merchandise_transactions mt
                    WHERE mt.station_id=? AND $mt_cust_cond
                    $mt_date_filter
                    ORDER BY $mt_date_col DESC
                ";
                $st = $pdo->prepare($mt_sql);
                $st->execute($mt_params);
                $mt_rows = $st->fetchAll(PDO::FETCH_ASSOC);

                // Fetch items summary for each merchandise transaction
                foreach ($mt_rows as &$mtr) {
                    try {
                        $is = $pdo->prepare("
                            SELECT COALESCE(ip.product_name, mti.product_name, 'Item') AS pname, mti.quantity
                            FROM merchandise_transaction_items mti
                            LEFT JOIN inventory_products ip ON ip.id = mti.product_id
                            WHERE mti.transaction_id = ?
                            LIMIT 5
                        ");
                        $is->execute([$mtr['id']]);
                        $items = $is->fetchAll(PDO::FETCH_ASSOC);
                        $mtr['merch_items_summary'] = implode(', ', array_map(fn($i) => $i['pname'] . ' ×' . $i['quantity'], $items));
                    } catch (Exception $e) {}
                }
                unset($mtr);
            }

            // Merge and sort by date desc
            $hist_records = array_merge($jo_rows, $mt_rows);
            usort($hist_records, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

            // Normalize payment_status
            foreach ($hist_records as &$hr) {
                $ps = strtolower(trim($hr['payment_status'] ?? ''));
                $total = (float)$hr['total_amount'];
                $paid  = (float)$hr['amount_paid'];
                if ($ps === 'paid' || $ps === 'completed' || $ps === 'approved') {
                    $hr['payment_status'] = 'Paid';
                } elseif ($ps === 'partial' || ($paid > 0 && $paid < $total)) {
                    $hr['payment_status'] = 'Partial';
                } else {
                    $hr['payment_status'] = 'Unpaid';
                }
            }
            unset($hr);

            // Apply payment status filter
            if ($hist_filter_status) {
                $hist_records = array_filter($hist_records, fn($r) => $r['payment_status'] === $hist_filter_status);
                $hist_records = array_values($hist_records);
            }
        } catch (Exception $e) {}
    }
}


// Section titles for page header
$section_titles = [
    'encode'   => ['fas fa-list',       'Customer List'],
    'history'  => ['fas fa-history',    'Customer History'],
    'linkage'  => ['fas fa-link',       'Transaction Linkage'],
];
[$sec_ico, $sec_title] = $section_titles[$section];

include __DIR__ . '/../partials/header.php';
?>
<style>
.cust-panel { display:none; }
.cust-panel.active { display:block; }
.cust-card { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.07); border:1px solid #e9ecef; margin-bottom:20px; }
.cust-card-head { padding:16px 20px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.cust-card-title { font-size:16px; font-weight:700; color:#002F70; margin:0; display:flex; align-items:center; gap:8px; }
.cust-card-body { padding:20px; }
.cust-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:600px){ .cust-form-grid { grid-template-columns:1fr; } }
.cust-label { display:block; font-size:12px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.cust-input { width:100%; padding:9px 12px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.cust-input:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.cust-btn { padding:10px 22px; border:none; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer; transition:all .2s; }
.cust-btn-primary { background:#002F70; color:#fff; }
.cust-btn-primary:hover { background:#0040a0; }
.cust-table { width:100%; border-collapse:collapse; font-size:13px; }
.cust-table th { background:#f8f9fa; padding:10px 12px; text-align:left; font-weight:700; color:#495057; border-bottom:2px solid #dee2e6; }
.cust-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.cust-table tr:hover td { background:#f8f9fa; }
.badge-active   { background:#d1fae5; color:#065f46; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.badge-inactive { background:#fee2e2; color:#991b1b; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.badge-pending  { background:#fef3c7; color:#92400e; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.badge-completed{ background:#d1fae5; color:#065f46; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.readonly-notice { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; font-size:12px; color:#1e40af; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.section-head { font-size:14px; font-weight:700; color:#002F70; margin:18px 0 10px; display:flex; align-items:center; gap:6px; border-bottom:2px solid #e9ecef; padding-bottom:8px; }
.empty-state { text-align:center; padding:32px; color:#9ca3af; }
.empty-state i { font-size:2rem; display:block; margin-bottom:8px; }
.cust-search { width:100%; padding:9px 12px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; margin-bottom:14px; box-sizing:border-box; }
.edit-link { color:#002F70; font-size:12px; font-weight:600; text-decoration:none; padding:4px 10px; border:1px solid #002F70; border-radius:5px; white-space:nowrap; }
.edit-link:hover { background:#002F70; color:#fff; }
.cust-tab { padding:10px 16px; border:none; background:transparent; font-size:14px; font-weight:700; color:#6c757d; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-1px; }
.cust-tab.active { color:#002F70; border-bottom-color:#002F70; }
.cust-tab:hover:not(.active) { color:#343a40; border-bottom-color:#dee2e6; }
</style>

<div class="page-head">
    <div>
        <h1><i class="<?= $sec_ico ?>"></i> <?= $sec_title ?></h1>
        <div class="page-subtitle">Station #<?= (int)$station_id ?> &mdash; Staff: encode, update, link transactions, view balances only</div>
    </div>
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

<!-- ══ SECTION: ENCODE ════════════════════════════════════════════════════════ -->
<?php if ($section === 'encode'): ?>

<div class="cust-card">
    <div class="cust-card-head">
        <h2 class="cust-card-title"><i class="fas fa-list"></i> Customer List</h2>
        <span style="font-size:13px;color:#6c757d;"><?= count($customers) ?> customers</span>
    </div>
    <div class="cust-card-body">
        <div class="readonly-notice">
            <i class="fas fa-info-circle"></i>
            <span>Customer records are managed by the manager. Contact your manager to add or edit customers.</span>
        </div>
        <input type="text" class="cust-search" id="encodeSearch" placeholder="&#128269; Search customers..." oninput="filterTable('encodeSearch','encodeTable')">
        <div style="overflow-x:auto;">
            <table class="cust-table" id="encodeTable">
                <thead><tr>
                    <th>ID</th><th>Name</th><th>Contact</th><th>ID Type</th><th>Credit Limit</th><th>Remaining Balance</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" class="empty-state"><i class="fas fa-users"></i>No customers yet.</td></tr>
                <?php else: foreach ($customers as $c): ?>
                    <tr data-search="<?= strtolower(htmlspecialchars($c['name'])) ?>">
                        <td style="color:#6c757d;font-size:12px;">#<?= (int)$c['id'] ?></td>
                        <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                        <td><?= htmlspecialchars($c['contact_number'] ?? '—') ?></td>
                        <td style="font-size:12px;color:#6c757d;"><?= htmlspecialchars($c['id_type'] ?? '—') ?></td>
                        <td>₱<?= number_format((float)$c['credit_limit'], 2) ?></td>
                        <?php
                            $remaining = (float)$c['credit_limit'] - (float)$c['balance'];
                            $rem_color = $remaining <= 0 ? '#dc3545' : ($remaining < (float)$c['credit_limit'] * 0.2 ? '#e67e22' : '#28a745');
                        ?>
                        <td style="color:<?= $rem_color ?>;font-weight:700;">
                            ₱<?= number_format($remaining, 2) ?>
                        </td>
                        <td><span class="badge-<?= $c['status']==='active'?'active':'inactive' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
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
                <?php $sel_remaining = (float)$sel_cust['credit_limit'] - (float)$sel_cust['balance']; ?>
                <span style="color:#6c757d;margin-left:12px;">Remaining Balance: <strong style="color:<?= $sel_remaining <= 0 ? '#dc3545' : '#28a745' ?>">₱<?= number_format($sel_remaining, 2) ?></strong></span>
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
                <div style="overflow-x:auto;margin-bottom:24px;">
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
                <div style="overflow-x:auto;">
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
/* ── Customer History Styles ─────────────────────────────── */
.ch-selector-card { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.07); border:1px solid #e9ecef; margin-bottom:18px; padding:18px 20px; }
.ch-filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:18px; }
.ch-filter-bar .ch-field { display:flex; flex-direction:column; gap:4px; }
.ch-filter-bar label { font-size:11px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; }
.ch-filter-bar select, .ch-filter-bar input { padding:8px 12px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; min-width:150px; }
.ch-filter-bar select:focus, .ch-filter-bar input:focus { border-color:#002F70; outline:none; }
.ch-info-bar { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
.ch-info-pill { background:#f0f4ff; border:1px solid #c7d7f9; border-radius:8px; padding:10px 16px; display:flex; flex-direction:column; gap:2px; min-width:140px; }
.ch-info-pill .pill-label { font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; }
.ch-info-pill .pill-value { font-size:16px; font-weight:800; color:#002F70; }
.ch-info-pill.danger .pill-value { color:#dc3545; }
.ch-info-pill.success .pill-value { color:#16a34a; }
.ch-table-wrap { overflow-x:auto; }
.ch-table { width:100%; border-collapse:collapse; font-size:13px; }
.ch-table th { background:#f8f9fa; padding:10px 12px; text-align:left; font-weight:700; color:#495057; border-bottom:2px solid #dee2e6; white-space:nowrap; }
.ch-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.ch-table tr:hover td { background:#fafbff; }
.ch-badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; white-space:nowrap; }
.ch-badge-jo   { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.ch-badge-merch{ background:#f0fdf4; color:#15803d; border:1px solid #86efac; }
.ch-badge-paid  { background:#d1fae5; color:#065f46; }
.ch-badge-unpaid{ background:#fee2e2; color:#991b1b; }
.ch-badge-partial{ background:#fef3c7; color:#92400e; }
.ch-badge-pending{ background:#f1f5f9; color:#475569; }
.ch-badge-approved{ background:#d1fae5; color:#065f46; }
.ch-badge-rejected{ background:#fee2e2; color:#991b1b; }
.ch-empty { text-align:center; padding:40px; color:#9ca3af; }
.ch-empty i { font-size:2rem; display:block; margin-bottom:8px; }
.ch-select-prompt { text-align:center; padding:48px 20px; color:#9ca3af; }
.ch-select-prompt i { font-size:2.5rem; display:block; margin-bottom:12px; color:#c7d7f9; }
.ch-select-prompt p { font-size:14px; margin:0; }
</style>

<div class="cust-card">
    <div class="cust-card-head">
        <h2 class="cust-card-title"><i class="fas fa-history"></i> Customer History</h2>
        <?php if ($hist_customer_info): ?>
        <span style="font-size:13px;color:#6c757d;"><?= count($hist_records) ?> record<?= count($hist_records) !== 1 ? 's' : '' ?> found</span>
        <?php endif; ?>
    </div>
    <div class="cust-card-body">

        <!-- Customer Selector + Filters -->
        <form method="GET" action="customers.php">
            <input type="hidden" name="section" value="history">
            <div class="ch-filter-bar">
                <div class="ch-field" style="flex:1;min-width:200px;">
                    <label>Select Customer</label>
                    <select name="cust_id" onchange="this.form.submit()" style="min-width:220px;">
                        <option value="">— Choose a customer —</option>
                        <?php foreach ($hist_customers as $hc): ?>
                        <option value="<?= (int)$hc['id'] ?>" <?= $hist_selected_id === (int)$hc['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($hc['name']) ?>
                            <?php $hc_rem = (float)$hc['credit_limit'] - (float)$hc['balance']; ?>
                            <?php if ($hc_rem < (float)$hc['credit_limit']): ?> · ₱<?= number_format($hc_rem, 2) ?> remaining<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($hist_selected_id): ?>
                <div class="ch-field">
                    <label>Type</label>
                    <select name="hist_type">
                        <option value="" <?= $hist_filter_type === '' ? 'selected' : '' ?>>All Types</option>
                        <option value="job_order"   <?= $hist_filter_type === 'job_order'   ? 'selected' : '' ?>>Job Order Only</option>
                        <option value="merchandise" <?= $hist_filter_type === 'merchandise' ? 'selected' : '' ?>>Merchandise Only</option>
                    </select>
                </div>
                <div class="ch-field">
                    <label>Payment Status</label>
                    <select name="hist_status">
                        <option value="" <?= $hist_filter_status === '' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="Paid"    <?= $hist_filter_status === 'Paid'    ? 'selected' : '' ?>>Paid</option>
                        <option value="Unpaid"  <?= $hist_filter_status === 'Unpaid'  ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Partial" <?= $hist_filter_status === 'Partial' ? 'selected' : '' ?>>Partial</option>
                    </select>
                </div>
                <div class="ch-field">
                    <label>Date</label>
                    <input type="date" name="hist_date" value="<?= htmlspecialchars($hist_filter_date) ?>">
                </div>
                <div class="ch-field" style="justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:6px;">
                        <button type="submit" class="cust-btn cust-btn-primary" style="padding:8px 16px;">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="customers.php?section=history&cust_id=<?= $hist_selected_id ?>"
                           class="cust-btn" style="background:#f1f5f9;color:#475569;padding:8px 14px;">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!$hist_selected_id): ?>
        <!-- No customer selected yet -->
        <div class="ch-select-prompt">
            <i class="fas fa-user-clock"></i>
            <p>Select a customer above to view their full transaction history.</p>
        </div>

        <?php else: ?>

        <!-- Customer Info Bar -->
        <?php if ($hist_customer_info): ?>
        <?php
            $ci_balance   = (float)$hist_customer_info['balance'];
            $ci_limit     = (float)$hist_customer_info['credit_limit'];
            $ci_remaining = $ci_limit - $ci_balance;   // remaining credit left
            // Compute totals from records
            $ci_total_txns   = count($hist_records);
            $ci_total_amount = array_sum(array_column($hist_records, 'total_amount'));
            $ci_unpaid_count = count(array_filter($hist_records, fn($r) => $r['payment_status'] === 'Unpaid'));
        ?>
        <div class="ch-info-bar">
            <div class="ch-info-pill">
                <span class="pill-label">Customer</span>
                <span class="pill-value" style="font-size:14px;"><?= htmlspecialchars($hist_customer_info['name']) ?></span>
            </div>
            <div class="ch-info-pill">
                <span class="pill-label">Credit Limit</span>
                <span class="pill-value">₱<?= number_format($ci_limit, 2) ?></span>
            </div>
            <div class="ch-info-pill <?= $ci_balance > 0 ? 'danger' : '' ?>">
                <span class="pill-label">Amount Used</span>
                <span class="pill-value">₱<?= number_format($ci_balance, 2) ?></span>
            </div>
            <div class="ch-info-pill <?= $ci_remaining <= 0 ? 'danger' : 'success' ?>">
                <span class="pill-label">Remaining Balance</span>
                <span class="pill-value">₱<?= number_format($ci_remaining, 2) ?></span>
            </div>
            <div class="ch-info-pill">
                <span class="pill-label">Total Transactions</span>
                <span class="pill-value"><?= $ci_total_txns ?></span>
            </div>
            <?php if ($ci_unpaid_count > 0): ?>
            <div class="ch-info-pill danger">
                <span class="pill-label">Unpaid Transactions</span>
                <span class="pill-value"><?= $ci_unpaid_count ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- History Table -->
        <?php if (empty($hist_records)): ?>
        <div class="ch-empty">
            <i class="fas fa-receipt"></i>
            No records found<?= ($hist_filter_type || $hist_filter_status || $hist_filter_date) ? ' for the selected filters.' : ' for this customer.' ?>
        </div>
        <?php else: ?>
        <div class="ch-table-wrap">
            <table class="ch-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Service / Items</th>
                        <th>Vehicle</th>
                        <th style="text-align:right;">Total</th>
                        <th>Payment</th>
                        <th>Pay Status</th>
                        <th>Txn Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hist_records as $idx => $hr):
                    $ps = $hr['payment_status'];
                    $ps_class = match($ps) {
                        'Paid'    => 'ch-badge-paid',
                        'Partial' => 'ch-badge-partial',
                        default   => 'ch-badge-unpaid',
                    };
                    $ts = strtolower($hr['txn_status'] ?? '');
                    $ts_class = match(true) {
                        str_contains($ts, 'approved') || str_contains($ts, 'completed') || str_contains($ts, 'verified') => 'ch-badge-approved',
                        str_contains($ts, 'rejected') || str_contains($ts, 'cancelled') => 'ch-badge-rejected',
                        default => 'ch-badge-pending',
                    };
                    $is_jo    = $hr['record_type'] === 'job_order';
                    $svc_text = $is_jo
                        ? htmlspecialchars($hr['service_label'] ?: '—')
                        : ($hr['merch_items_summary'] ?: (htmlspecialchars($hr['service_label'] ?: '—')));
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:11px;"><?= count($hist_records) - $idx ?></td>
                    <td>
                        <span style="font-family:monospace;font-size:11px;font-weight:700;color:#002F70;">
                            <?= htmlspecialchars($hr['ref_number']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="ch-badge <?= $is_jo ? 'ch-badge-jo' : 'ch-badge-merch' ?>">
                            <i class="fas <?= $is_jo ? 'fa-tools' : 'fa-shopping-cart' ?>" style="margin-right:3px;"></i>
                            <?= $is_jo ? 'Job Order' : 'Merchandise' ?>
                        </span>
                    </td>
                    <td style="max-width:220px;font-size:12px;color:#374151;">
                        <?= $svc_text ?>
                    </td>
                    <td style="font-size:12px;color:#6c757d;">
                        <?= $hr['vehicle_plate'] ? htmlspecialchars($hr['vehicle_plate']) : '—' ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:#002F70;white-space:nowrap;">
                        ₱<?= number_format((float)$hr['total_amount'], 2) ?>
                    </td>
                    <td style="font-size:12px;color:#6c757d;">
                        <?= htmlspecialchars($hr['payment_method'] ?: '—') ?>
                    </td>
                    <td>
                        <span class="ch-badge <?= $ps_class ?>">
                            <?php if ($ps === 'Paid'): ?><i class="fas fa-check-circle" style="margin-right:3px;"></i>
                            <?php elseif ($ps === 'Partial'): ?><i class="fas fa-adjust" style="margin-right:3px;"></i>
                            <?php else: ?><i class="fas fa-clock" style="margin-right:3px;"></i><?php endif; ?>
                            <?= $ps ?>
                        </span>
                    </td>
                    <td>
                        <span class="ch-badge <?= $ts_class ?>">
                            <?= htmlspecialchars(ucfirst($hr['txn_status'] ?? 'Pending')) ?>
                        </span>
                    </td>
                    <td style="font-size:11px;color:#6c757d;white-space:nowrap;">
                        <?= date('M j, Y', strtotime($hr['txn_date'])) ?><br>
                        <span style="color:#9ca3af;"><?= date('h:i A', strtotime($hr['txn_date'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Summary footer -->
        <div style="display:flex;justify-content:flex-end;padding:12px 4px 0;gap:24px;font-size:13px;border-top:1px solid #f0f0f0;margin-top:4px;">
            <span style="color:#6c757d;">
                <?= count($hist_records) ?> record<?= count($hist_records) !== 1 ? 's' : '' ?>
            </span>
            <span style="font-weight:700;color:#002F70;">
                Total: ₱<?= number_format(array_sum(array_column($hist_records, 'total_amount')), 2) ?>
            </span>
        </div>
        <?php endif; ?>

        <?php endif; // end $hist_selected_id ?>
    </div>
</div>

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
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
