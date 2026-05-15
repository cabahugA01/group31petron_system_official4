<?php
$page_id = 'mgr_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$valid_sections = ['validation','updates','history','balances','transactions','transparency'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'validation';

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
        'id_number'      => "VARCHAR(100) NULL",
        'credit_limit'   => "DECIMAL(12,2) DEFAULT 0.00",
        'balance'        => "DECIMAL(12,2) DEFAULT 0.00",
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

// ── POST handlers ─────────────────────────────────────────────────────────────
$flash_ok = $flash_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

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

// ── Section data ──────────────────────────────────────────────────────────────
$pending_customers = $update_requests = $balance_customers = [];
$txn_customers = $job_orders_linked = $merch_linked = $transparency_data = [];
$txn_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if ($section === 'validation') {
    try {
        $mgr_col = in_array('mgr_status',$avail) ? 'mgr_status' : "'pending'";
        $s = $pdo->prepare("SELECT c.*, COALESCE(u.name,'—') AS encoded_by_name
            FROM customers c LEFT JOIN users u ON u.id=c.created_by
            WHERE c.station_id=? AND ($mgr_col='pending' OR $mgr_col IS NULL)
            ORDER BY c.created_at DESC");
        $s->execute([$station_id]);
        $pending_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}

if ($section === 'updates') {
    try {
        $s = $pdo->prepare("SELECT cur.*, c.name AS customer_name, COALESCE(u.name,'—') AS requested_by_name
            FROM customer_update_requests cur
            LEFT JOIN customers c ON c.id=cur.customer_id
            LEFT JOIN users u ON u.id=cur.requested_by
            WHERE cur.station_id=? AND cur.status='pending'
            ORDER BY cur.created_at DESC");
        $s->execute([$station_id]);
        $update_requests = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}

if ($section === 'balances') {
    try {
        $s = $pdo->prepare("SELECT id, name, COALESCE($bal_col,0) AS balance,
            COALESCE(credit_limit,0) AS credit_limit, status,
            COALESCE(contact_number,'—') AS contact_number
            FROM customers WHERE station_id=? ORDER BY balance DESC");
        $s->execute([$station_id]);
        $balance_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}

if ($section === 'transactions') {
    try {
        $s = $pdo->prepare("SELECT id, name FROM customers WHERE station_id=? ORDER BY name");
        $s->execute([$station_id]);
        $txn_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
    if ($txn_customer_id) {
        try {
            $jc = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
            $jo_num  = in_array('job_order_id',$jc) ? 'job_order_id' : (in_array('jo_number',$jc) ? 'jo_number' : 'NULL');
            $svc     = in_array('service_type',$jc) ? 'service_type' : (in_array('service_description',$jc) ? 'service_description' : "'—'");
            $pay     = in_array('payment_method',$jc) ? 'payment_method' : 'NULL';
            $has_cc  = in_array('credit_customer_id',$jc);
            $cond    = $has_cc ? '(customer_id=? OR credit_customer_id=?)' : 'customer_id=?';
            $params  = $has_cc ? [$station_id,$txn_customer_id,$txn_customer_id] : [$station_id,$txn_customer_id];
            $s = $pdo->prepare("SELECT id, COALESCE($jo_num,CONCAT('JO-',id)) AS jo_ref,
                COALESCE($svc,'—') AS service, $pay AS payment_method,
                COALESCE(estimated_cost,0) AS amount, created_at, status
                FROM job_orders WHERE station_id=? AND $cond ORDER BY created_at DESC");
            $s->execute($params);
            $job_orders_linked = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e){}
        try {
            $mc = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('customer_id',$mc)) {
                $dc = in_array('transaction_date',$mc) ? 'COALESCE(transaction_date,created_at)' : 'created_at';
                $s = $pdo->prepare("SELECT id, customer_name, total_amount, payment_method,
                    $dc AS txn_date FROM merchandise_transactions
                    WHERE station_id=? AND customer_id=? ORDER BY txn_date DESC");
                $s->execute([$station_id,$txn_customer_id]);
                $merch_linked = $s->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch(Exception $e){}
    }
}

// ── Customer History data (Manager view) ─────────────────────────────────────
$hist_customers      = [];
$hist_selected_id    = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;
$hist_filter_type    = $_GET['hist_type']   ?? '';
$hist_filter_status  = $_GET['hist_status'] ?? '';
$hist_filter_date    = $_GET['hist_date']   ?? '';
$hist_records        = [];
$hist_customer_info  = null;
if ($section === 'history') {
    try {
        $s = $pdo->prepare("SELECT id, name, balance, credit_limit, status FROM customers WHERE station_id=? ORDER BY name ASC");
        $s->execute([$station_id]);
        $hist_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if ($hist_selected_id) {
        foreach ($hist_customers as $hc) {
            if ($hc['id'] === $hist_selected_id) { $hist_customer_info = $hc; break; }
        }

        try {
            $jo_cols  = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
            $mt_cols  = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);

            $has_jo_credit  = in_array('credit_customer_id', $jo_cols);
            $has_mt_cid     = in_array('customer_id', $mt_cols);
            $has_mt_credit  = in_array('credit_customer_id', $mt_cols);
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
                ? '(jo.customer_id=? OR jo.credit_customer_id=?)'
                : 'jo.customer_id=?';
            $jo_params      = $has_jo_credit
                ? [$station_id, $hist_selected_id, $hist_selected_id]
                : [$station_id, $hist_selected_id];

            $mt_date_col    = in_array('transaction_date', $mt_cols) ? 'COALESCE(mt.transaction_date, mt.created_at)' : 'mt.created_at';
            $mt_cust_cond   = $has_mt_credit
                ? '(mt.customer_id=? OR mt.credit_customer_id=?)'
                : ($has_mt_cid ? 'mt.customer_id=?' : '1=0');
            $mt_params      = ($has_mt_credit || $has_mt_cid)
                ? ($has_mt_credit ? [$station_id, $hist_selected_id, $hist_selected_id] : [$station_id, $hist_selected_id])
                : [$station_id];

            $jo_date_filter = $mt_date_filter = '';
            if ($hist_filter_date) {
                $jo_date_filter  = " AND DATE(jo.created_at) = " . $pdo->quote($hist_filter_date);
                $mt_date_filter  = " AND DATE($mt_date_col) = " . $pdo->quote($hist_filter_date);
            }

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

            $hist_records = array_merge($jo_rows, $mt_rows);
            usort($hist_records, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

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

            if ($hist_filter_status) {
                $hist_records = array_filter($hist_records, fn($r) => $r['payment_status'] === $hist_filter_status);
                $hist_records = array_values($hist_records);
            }
        } catch (Exception $e) {}
    }
}

if ($section === 'transparency') {    try {
        $s = $pdo->prepare("SELECT c.id, c.name,
            COALESCE($bal_col,0) AS balance,
            COALESCE(credit_limit,0) AS credit_limit,
            c.status, COALESCE(c.contact_number,'—') AS contact_number,
            (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id=c.id
             AND mt.payment_method IN ('Account Receivable','Credit','Utang','utang','credit')) AS utang_count,
            (SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions mt WHERE mt.customer_id=c.id
             AND mt.payment_method IN ('Account Receivable','Credit','Utang','utang','credit')) AS total_utang
            FROM customers c WHERE c.station_id=? ORDER BY balance DESC");
        $s->execute([$station_id]);
        $transparency_data = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}

$section_meta = [
    'validation'   => ['fas fa-user-check', 'Customer Validation'],
    'updates'      => ['fas fa-user-edit',   'Customer Updates Approval'],
    'history'      => ['fas fa-history',     'Customer History'],
    'balances'     => ['fas fa-wallet',      'Balances Monitoring'],
    'transactions' => ['fas fa-receipt',     'Transaction Oversight'],
    'transparency' => ['fas fa-eye',         'Transparency Tab'],
];
[$sec_ico, $sec_title] = $section_meta[$section];

include __DIR__ . '/../partials/header.php';
?>
<style>
.mgrc-card{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;}
.mgrc-head{padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.mgrc-title{font-size:16px;font-weight:700;color:#002F70;margin:0;display:flex;align-items:center;gap:8px;}
.mgrc-body{padding:20px;}
.mgrc-table{width:100%;border-collapse:collapse;font-size:13px;}
.mgrc-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#495057;border-bottom:2px solid #dee2e6;}
.mgrc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.mgrc-table tr:hover td{background:#f8f9fa;}
.badge-pending{background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-approved{background:#d1fae5;color:#065f46;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-rejected{background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-active{background:#d1fae5;color:#065f46;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-inactive{background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-overdue{background:#fde8d8;color:#9a3412;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.mgrc-btn{padding:6px 14px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:5px;}
.mgrc-btn-approve{background:#28a745;color:#fff;} .mgrc-btn-approve:hover{background:#218838;}
.mgrc-btn-reject{background:#dc3545;color:#fff;} .mgrc-btn-reject:hover{background:#c82333;}
.mgrc-btn-view{background:#002F70;color:#fff;text-decoration:none;} .mgrc-btn-view:hover{background:#0040a0;}
.mgrc-empty{text-align:center;padding:40px;color:#9ca3af;}
.mgrc-empty i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4;}
.mgrc-search{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;margin-bottom:14px;box-sizing:border-box;}
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
@media(max-width:640px){.mgrc-bal-grid{grid-template-columns:1fr;}}
</style>
<div class="page-head">
  <div>
    <h1 class="h1"><i class="<?php echo $sec_ico; ?>"></i> <?php echo $sec_title; ?></h1>
    <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Manager customer oversight</div>
  </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><i class="fas fa-check-circle"></i> <?php echo $flash_ok; ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>

<!-- ===== SECTION: VALIDATION ===== -->
<?php if ($section === 'validation'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-check"></i> Pending Customer Profiles</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($pending_customers); ?> pending</span>
  </div>
  <div class="mgrc-body">
    <div class="mgrc-info-box"><i class="fas fa-info-circle"></i> Review customer profiles encoded by staff. Approve to activate, or reject with notes.</div>
    <?php if (empty($pending_customers)): ?>
      <div class="mgrc-empty"><i class="fas fa-user-check"></i><strong>No pending customers</strong><br><small>All customer profiles have been reviewed.</small></div>
    <?php else: ?>
    <input class="mgrc-search" id="valSearch" placeholder="Search customers..." oninput="filterRows('valSearch','valTable')">
    <div style="overflow-x:auto;">
    <table class="mgrc-table" id="valTable">
      <thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Credit Limit</th><th>Encoded By</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($pending_customers as $c): ?>
      <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
        <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$c['id']; ?></td>
        <td><strong><?php echo htmlspecialchars($c['name']); ?></strong><br><small style="color:#6c757d;"><?php echo htmlspecialchars($c['id_number'] ?? '—'); ?></small></td>
        <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
        <td>&#8369;<?php echo number_format((float)($c['credit_limit'] ?? 0),2); ?></td>
        <td><?php echo htmlspecialchars($c['encoded_by_name'] ?? '—'); ?></td>
        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
        <td style="white-space:nowrap;">
          <button class="mgrc-btn mgrc-btn-approve" onclick="openModal('validate',<?php echo (int)$c['id']; ?>,'<?php echo htmlspecialchars($c['name'],ENT_QUOTES); ?>','approved')"><i class="fas fa-check"></i> Approve</button>
          <button class="mgrc-btn mgrc-btn-reject" style="margin-left:4px;" onclick="openModal('validate',<?php echo (int)$c['id']; ?>,'<?php echo htmlspecialchars($c['name'],ENT_QUOTES); ?>','rejected')"><i class="fas fa-times"></i> Reject</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: UPDATES ===== -->
<?php if ($section === 'updates'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-edit"></i> Pending Update Requests</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($update_requests); ?> pending</span>
  </div>
  <div class="mgrc-body">
    <div class="mgrc-info-box"><i class="fas fa-info-circle"></i> Staff-submitted edit requests for customer info. Approve to apply the change, or reject to keep the original.</div>
    <?php if (empty($update_requests)): ?>
      <div class="mgrc-empty"><i class="fas fa-user-edit"></i><strong>No pending update requests</strong><br><small>All changes have been reviewed.</small></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="mgrc-table">
      <thead><tr><th>Req #</th><th>Customer</th><th>Field</th><th>Old Value</th><th>New Value</th><th>Requested By</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($update_requests as $r): ?>
      <tr>
        <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$r['id']; ?></td>
        <td><strong><?php echo htmlspecialchars($r['customer_name'] ?? '—'); ?></strong></td>
        <td><span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($r['field_name']); ?></span></td>
        <td style="color:#dc3545;font-size:12px;"><?php echo htmlspecialchars($r['old_value'] ?? '—'); ?></td>
        <td style="color:#28a745;font-size:12px;font-weight:700;"><?php echo htmlspecialchars($r['new_value'] ?? '—'); ?></td>
        <td><?php echo htmlspecialchars($r['requested_by_name'] ?? '—'); ?></td>
        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
        <td style="white-space:nowrap;">
          <button class="mgrc-btn mgrc-btn-approve" onclick="openModal('update',<?php echo (int)$r['id']; ?>,'<?php echo htmlspecialchars($r['customer_name'] ?? '',ENT_QUOTES); ?>','approved')"><i class="fas fa-check"></i> Approve</button>
          <button class="mgrc-btn mgrc-btn-reject" style="margin-left:4px;" onclick="openModal('update',<?php echo (int)$r['id']; ?>,'<?php echo htmlspecialchars($r['customer_name'] ?? '',ENT_QUOTES); ?>','rejected')"><i class="fas fa-times"></i> Reject</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: BALANCES ===== -->
<?php if ($section === 'balances'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-wallet"></i> Customer Balances</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($balance_customers); ?> customers</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="balSearch" placeholder="Search customers..." oninput="filterCards('balSearch')">
    <?php if (empty($balance_customers)): ?>
      <div class="mgrc-empty"><i class="fas fa-wallet"></i><strong>No customers found</strong></div>
    <?php else: ?>
    <div class="mgrc-bal-grid" id="balGrid">
      <?php foreach ($balance_customers as $c):
        $bal = (float)($c['balance'] ?? 0);
        $lim = (float)($c['credit_limit'] ?? 0);
        $avail_credit = max(0, $lim - $bal);
        $pct = $lim > 0 ? min(100, round($bal / $lim * 100)) : 0;
        $color = $bal <= 0 ? '#28a745' : ($pct >= 80 ? '#dc3545' : '#e67e22');
        $st = strtolower($c['status'] ?? 'active');
      ?>
      <div class="mgrc-bal-card" data-name="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
        <div class="mgrc-bal-name"><?php echo htmlspecialchars($c['name']); ?></div>
        <div class="mgrc-bal-amount" style="color:<?php echo $color; ?>">
          &#8369;<?php echo number_format($bal, 2); ?>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:6px;margin-bottom:10px;">
          <div style="background:<?php echo $color; ?>;height:6px;border-radius:4px;width:<?php echo $pct; ?>%;transition:width .4s;"></div>
        </div>
        <div class="mgrc-bal-detail"><span>Credit Limit</span><span>&#8369;<?php echo number_format($lim,2); ?></span></div>
        <div class="mgrc-bal-detail"><span>Available Credit</span><span>&#8369;<?php echo number_format($avail_credit,2); ?></span></div>
        <div class="mgrc-bal-detail"><span>Contact</span><span><?php echo htmlspecialchars($c['contact_number']); ?></span></div>
        <div class="mgrc-bal-detail"><span>Status</span><span><span class="badge-<?php echo $st; ?>"><?php echo ucfirst($st); ?></span></span></div>
        <div style="margin-top:10px;">
          <a href="manager_customers.php?section=transactions&customer_id=<?php echo (int)$c['id']; ?>" class="mgrc-btn mgrc-btn-view" style="font-size:11px;"><i class="fas fa-receipt"></i> View Transactions</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: TRANSACTIONS ===== -->
<?php if ($section === 'transactions'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-receipt"></i> Transaction Oversight</h2>
  </div>
  <div class="mgrc-body">
    <form method="GET" action="manager_customers.php" style="margin-bottom:20px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="section" value="transactions">
      <div>
        <label style="display:block;font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;margin-bottom:5px;">Select Customer</label>
        <select name="customer_id" class="cust-sel">
          <option value="">— Select a customer —</option>
          <?php foreach ($txn_customers as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $txn_customer_id==(int)$c['id']?'selected':''; ?>>
            #<?php echo (int)$c['id']; ?> — <?php echo htmlspecialchars($c['name']); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="mgrc-btn mgrc-btn-view"><i class="fas fa-search"></i> View</button>
    </form>

    <?php if ($txn_customer_id): ?>
    <div class="mgrc-section-head"><i class="fas fa-wrench"></i> Job Orders</div>
    <?php if (empty($job_orders_linked)): ?>
      <div class="mgrc-empty"><i class="fas fa-wrench"></i>No job orders for this customer.</div>
    <?php else: ?>
    <div style="overflow-x:auto;margin-bottom:24px;">
    <table class="mgrc-table">
      <thead><tr><th>JO Ref</th><th>Service</th><th>Payment Method</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($job_orders_linked as $j):
        $st = strtolower($j['status'] ?? '');
        $bc = $st==='completed'?'approved':($st==='rejected'?'rejected':'pending');
      ?>
      <tr>
        <td><strong style="color:#002F70;"><?php echo htmlspecialchars($j['jo_ref']); ?></strong></td>
        <td><?php echo htmlspecialchars($j['service']); ?></td>
        <td><?php echo htmlspecialchars($j['payment_method'] ?? '—'); ?></td>
        <td>&#8369;<?php echo number_format((float)$j['amount'],2); ?></td>
        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y',strtotime($j['created_at'])); ?></td>
        <td><span class="badge-<?php echo $bc; ?>"><?php echo htmlspecialchars($j['status'] ?? '—'); ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

    <div class="mgrc-section-head"><i class="fas fa-shopping-cart"></i> Merchandise Transactions</div>
    <?php if (empty($merch_linked)): ?>
      <div class="mgrc-empty"><i class="fas fa-shopping-cart"></i>No merchandise transactions for this customer.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="mgrc-table">
      <thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Payment Method</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($merch_linked as $mt): ?>
      <tr>
        <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$mt['id']; ?></td>
        <td><?php echo htmlspecialchars($mt['customer_name'] ?? '—'); ?></td>
        <td style="color:#065f46;font-weight:700;">&#8369;<?php echo number_format((float)$mt['total_amount'],2); ?></td>
        <td><?php echo htmlspecialchars($mt['payment_method'] ?? '—'); ?></td>
        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y',strtotime($mt['txn_date'])); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php else: ?>
      <div class="mgrc-empty"><i class="fas fa-hand-pointer"></i>Select a customer above to view their transactions.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: CUSTOMER HISTORY (Manager) ===== -->
<?php if ($section === 'history'): ?>
<style>
.ch-filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.ch-filter-bar .ch-field{display:flex;flex-direction:column;gap:4px;}
.ch-filter-bar label{font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;}
.ch-filter-bar select,.ch-filter-bar input{padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:150px;}
.ch-info-bar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.ch-info-pill{background:#f0f4ff;border:1px solid #c7d7f9;border-radius:8px;padding:10px 16px;display:flex;flex-direction:column;gap:2px;min-width:140px;}
.ch-info-pill .pill-label{font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;}
.ch-info-pill .pill-value{font-size:16px;font-weight:800;color:#002F70;}
.ch-info-pill.danger .pill-value{color:#dc3545;}
.ch-info-pill.success .pill-value{color:#16a34a;}
.ch-table-wrap{overflow-x:auto;}
.ch-table{width:100%;border-collapse:collapse;font-size:13px;}
.ch-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#495057;border-bottom:2px solid #dee2e6;white-space:nowrap;}
.ch-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.ch-table tr:hover td{background:#fafbff;}
.ch-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.ch-badge-jo{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
.ch-badge-merch{background:#f0fdf4;color:#15803d;border:1px solid #86efac;}
.ch-badge-paid{background:#d1fae5;color:#065f46;}
.ch-badge-unpaid{background:#fee2e2;color:#991b1b;}
.ch-badge-partial{background:#fef3c7;color:#92400e;}
.ch-badge-pending{background:#f1f5f9;color:#475569;}
.ch-badge-approved{background:#d1fae5;color:#065f46;}
.ch-badge-rejected{background:#fee2e2;color:#991b1b;}
.ch-empty{text-align:center;padding:40px;color:#9ca3af;}
.ch-empty i{font-size:2rem;display:block;margin-bottom:8px;}
.ch-select-prompt{text-align:center;padding:48px 20px;color:#9ca3af;}
.ch-select-prompt i{font-size:2.5rem;display:block;margin-bottom:12px;color:#c7d7f9;}
</style>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-history"></i> Customer History</h2>
    <?php if ($hist_customer_info): ?>
    <span style="font-size:13px;color:#6c757d;"><?= count($hist_records) ?> record<?= count($hist_records) !== 1 ? 's' : '' ?></span>
    <?php endif; ?>
  </div>
  <div class="mgrc-body">

    <form method="GET" action="manager_customers.php">
      <input type="hidden" name="section" value="history">
      <div class="ch-filter-bar">
        <div class="ch-field" style="flex:1;min-width:200px;">
          <label>Select Customer</label>
          <select name="cust_id" onchange="this.form.submit()" style="min-width:220px;">
            <option value="">— Choose a customer —</option>
            <?php foreach ($hist_customers as $hc): ?>
            <option value="<?= (int)$hc['id'] ?>" <?= $hist_selected_id === (int)$hc['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($hc['name']) ?>
              <?php if ((float)$hc['balance'] > 0): ?> · ₱<?= number_format((float)$hc['balance'], 2) ?> balance<?php endif; ?>
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
            <button type="submit" style="padding:8px 16px;background:#002F70;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
              <i class="fas fa-filter"></i> Filter
            </button>
            <a href="manager_customers.php?section=history&cust_id=<?= $hist_selected_id ?>"
               style="padding:8px 14px;background:#f1f5f9;color:#475569;border:1px solid #dee2e6;border-radius:6px;font-size:13px;text-decoration:none;">
              <i class="fas fa-times"></i>
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </form>

    <?php if (!$hist_selected_id): ?>
    <div class="ch-select-prompt">
      <i class="fas fa-user-clock"></i>
      <p>Select a customer above to view their full transaction history.</p>
    </div>
    <?php else: ?>

    <?php if ($hist_customer_info): ?>
    <?php
      $ci_balance   = (float)$hist_customer_info['balance'];
      $ci_limit     = (float)$hist_customer_info['credit_limit'];
      $ci_available = $ci_limit - $ci_balance;
      $ci_unpaid_count = count(array_filter($hist_records, fn($r) => $r['payment_status'] === 'Unpaid'));
    ?>
    <div class="ch-info-bar">
      <div class="ch-info-pill">
        <span class="pill-label">Customer</span>
        <span class="pill-value" style="font-size:14px;"><?= htmlspecialchars($hist_customer_info['name']) ?></span>
      </div>
      <div class="ch-info-pill <?= $ci_balance > 0 ? 'danger' : 'success' ?>">
        <span class="pill-label">Outstanding Balance</span>
        <span class="pill-value">₱<?= number_format($ci_balance, 2) ?></span>
      </div>
      <div class="ch-info-pill">
        <span class="pill-label">Credit Limit</span>
        <span class="pill-value">₱<?= number_format($ci_limit, 2) ?></span>
      </div>
      <div class="ch-info-pill <?= $ci_available < 0 ? 'danger' : '' ?>">
        <span class="pill-label">Available Credit</span>
        <span class="pill-value">₱<?= number_format($ci_available, 2) ?></span>
      </div>
      <div class="ch-info-pill">
        <span class="pill-label">Total Transactions</span>
        <span class="pill-value"><?= count($hist_records) ?></span>
      </div>
      <?php if ($ci_unpaid_count > 0): ?>
      <div class="ch-info-pill danger">
        <span class="pill-label">Unpaid Transactions</span>
        <span class="pill-value"><?= $ci_unpaid_count ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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
            <th>#</th><th>Reference</th><th>Type</th><th>Service / Items</th>
            <th>Vehicle</th><th style="text-align:right;">Total</th>
            <th>Payment</th><th>Pay Status</th><th>Txn Status</th><th>Date</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($hist_records as $idx => $hr):
          $ps = $hr['payment_status'];
          $ps_class = match($ps) { 'Paid' => 'ch-badge-paid', 'Partial' => 'ch-badge-partial', default => 'ch-badge-unpaid' };
          $ts = strtolower($hr['txn_status'] ?? '');
          $ts_class = match(true) {
            str_contains($ts,'approved')||str_contains($ts,'completed')||str_contains($ts,'verified') => 'ch-badge-approved',
            str_contains($ts,'rejected')||str_contains($ts,'cancelled') => 'ch-badge-rejected',
            default => 'ch-badge-pending',
          };
          $is_jo = $hr['record_type'] === 'job_order';
          $svc_text = $is_jo
            ? htmlspecialchars($hr['service_label'] ?: '—')
            : ($hr['merch_items_summary'] ?: htmlspecialchars($hr['service_label'] ?: '—'));
        ?>
        <tr>
          <td style="color:#9ca3af;font-size:11px;"><?= count($hist_records) - $idx ?></td>
          <td><span style="font-family:monospace;font-size:11px;font-weight:700;color:#002F70;"><?= htmlspecialchars($hr['ref_number']) ?></span></td>
          <td>
            <span class="ch-badge <?= $is_jo ? 'ch-badge-jo' : 'ch-badge-merch' ?>">
              <i class="fas <?= $is_jo ? 'fa-tools' : 'fa-shopping-cart' ?>" style="margin-right:3px;"></i>
              <?= $is_jo ? 'Job Order' : 'Merchandise' ?>
            </span>
          </td>
          <td style="max-width:220px;font-size:12px;color:#374151;"><?= $svc_text ?></td>
          <td style="font-size:12px;color:#6c757d;"><?= $hr['vehicle_plate'] ? htmlspecialchars($hr['vehicle_plate']) : '—' ?></td>
          <td style="text-align:right;font-weight:700;color:#002F70;white-space:nowrap;">₱<?= number_format((float)$hr['total_amount'], 2) ?></td>
          <td style="font-size:12px;color:#6c757d;"><?= htmlspecialchars($hr['payment_method'] ?: '—') ?></td>
          <td>
            <span class="ch-badge <?= $ps_class ?>">
              <?php if ($ps==='Paid'): ?><i class="fas fa-check-circle" style="margin-right:3px;"></i>
              <?php elseif ($ps==='Partial'): ?><i class="fas fa-adjust" style="margin-right:3px;"></i>
              <?php else: ?><i class="fas fa-clock" style="margin-right:3px;"></i><?php endif; ?>
              <?= $ps ?>
            </span>
          </td>
          <td><span class="ch-badge <?= $ts_class ?>"><?= htmlspecialchars(ucfirst($hr['txn_status'] ?? 'Pending')) ?></span></td>
          <td style="font-size:11px;color:#6c757d;white-space:nowrap;">
            <?= date('M j, Y', strtotime($hr['txn_date'])) ?><br>
            <span style="color:#9ca3af;"><?= date('h:i A', strtotime($hr['txn_date'])) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;justify-content:flex-end;padding:12px 4px 0;gap:24px;font-size:13px;border-top:1px solid #f0f0f0;margin-top:4px;">
      <span style="color:#6c757d;"><?= count($hist_records) ?> record<?= count($hist_records) !== 1 ? 's' : '' ?></span>
      <span style="font-weight:700;color:#002F70;">Total: ₱<?= number_format(array_sum(array_column($hist_records, 'total_amount')), 2) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; // end $hist_selected_id ?>
  </div>
</div>
<?php endif; // end section === 'history' ?>

<!-- ===== SECTION: TRANSPARENCY ===== -->
<?php if ($section === 'transparency'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-eye"></i> Customer Credit Transparency</h2>
    <span style="font-size:13px;color:#6c757d;">Full credit history &amp; linkage</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="transSearch" placeholder="Search customers..." oninput="filterRows('transSearch','transTable')">
    <?php if (empty($transparency_data)): ?>
      <div class="mgrc-empty"><i class="fas fa-check-circle" style="color:#28a745;"></i><strong>No customers with outstanding balances.</strong></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="mgrc-table" id="transTable">
      <thead><tr><th>Customer</th><th>Contact</th><th>Balance</th><th>Credit Limit</th><th>Utang Txns</th><th>Total Utang</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($transparency_data as $c):
        $bal = (float)($c['balance'] ?? 0);
        $lim = (float)($c['credit_limit'] ?? 0);
        $st  = strtolower($c['status'] ?? 'active');
      ?>
      <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
        <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
        <td style="font-size:12px;"><?php echo htmlspecialchars($c['contact_number']); ?></td>
        <td style="font-weight:700;color:<?php echo $bal>0?'#dc3545':'#28a745'; ?>;">&#8369;<?php echo number_format($bal,2); ?></td>
        <td>&#8369;<?php echo number_format($lim,2); ?></td>
        <td style="text-align:center;"><?php echo (int)$c['utang_count']; ?></td>
        <td style="font-weight:700;color:#dc3545;">&#8369;<?php echo number_format((float)$c['total_utang'],2); ?></td>
        <td><span class="badge-<?php echo $st; ?>"><?php echo ucfirst($st); ?></span></td>
        <td>
          <a href="manager_customers.php?section=transactions&customer_id=<?php echo (int)$c['id']; ?>" class="mgrc-btn mgrc-btn-view" style="font-size:11px;"><i class="fas fa-receipt"></i> Transactions</a>
        </td>
      </tr>
      <?php endforeach; ?>
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
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
