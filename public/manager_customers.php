<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$valid_sections = ['records','balances','validation','transactions'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'records';

$page_id = match($section) {
    'balances' => 'mgr_cust_balances',
    default    => 'mgr_cust_list',
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
        'id_number'      => "VARCHAR(100) NULL",
        'id_type'        => "VARCHAR(100) NULL",
        'id_image'       => "VARCHAR(255) NULL",
        'cr_image'       => "VARCHAR(255) NULL",
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
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $name       = trim($first_name . ' ' . $last_name);
        $contact = trim($_POST['contact'] ?? '');
        $id_type = trim($_POST['id_type'] ?? '');
        $credit  = (float)($_POST['credit_limit'] ?? 0);

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
                $val_list = [$name, $station_id, 'active', 'approved', date('Y-m-d H:i:s')];
                if (in_array('contact_number', $ins_cols)) { $col_list[] = 'contact_number'; $val_list[] = $contact; }
                if (in_array('id_type',        $ins_cols)) { $col_list[] = 'id_type';        $val_list[] = $id_type; }
                if (in_array('id_image',       $ins_cols)) { $col_list[] = 'id_image';       $val_list[] = $id_image_path; }
                if (in_array('cr_image',       $ins_cols)) { $col_list[] = 'cr_image';       $val_list[] = $cr_image_path; }
                if (in_array('credit_limit',   $ins_cols)) { $col_list[] = 'credit_limit';   $val_list[] = $credit; }
                if (in_array('balance',        $ins_cols)) { $col_list[] = 'balance';         $val_list[] = 0; }
                $placeholders = implode(',', array_fill(0, count($col_list), '?'));
                $pdo->prepare("INSERT INTO customers (" . implode(',', $col_list) . ") VALUES ($placeholders)")
                    ->execute($val_list);
                $flash_ok = "Customer \"" . htmlspecialchars($name) . "\" added successfully.";
                // ── Audit log ──
                $new_cid = (int)$pdo->lastInsertId();
                write_audit_log($pdo, 'Create',
                    "New customer encoded: {$name}" . ($id_type ? " | ID Type: {$id_type}" : '') . " | Credit Limit: ₱" . number_format($credit, 2),
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
        $contact = trim($_POST['contact'] ?? '');
        $id_type = trim($_POST['id_type'] ?? '');
        $credit  = (float)($_POST['credit_limit'] ?? 0);

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
                $set_parts = ['name=?'];
                $upd_vals  = [$name];
                if (in_array('contact_number', $upd_cols)) { $set_parts[] = 'contact_number=?'; $upd_vals[] = $contact; }
                if (in_array('id_type',        $upd_cols)) { $set_parts[] = 'id_type=?';        $upd_vals[] = $id_type; }
                if ($id_image_path && in_array('id_image', $upd_cols)) { $set_parts[] = 'id_image=?'; $upd_vals[] = $id_image_path; }
                if ($cr_image_path && in_array('cr_image', $upd_cols)) { $set_parts[] = 'cr_image=?'; $upd_vals[] = $cr_image_path; }
                if (in_array('credit_limit',   $upd_cols)) { $set_parts[] = 'credit_limit=?';   $upd_vals[] = $credit; }
                $upd_vals[] = $cid;
                $upd_vals[] = $station_id;
                $pdo->prepare("UPDATE customers SET " . implode(',', $set_parts) . " WHERE id=? AND station_id=?")
                    ->execute($upd_vals);
                $flash_ok = "Customer updated successfully.";
                // ── Audit log ──
                write_audit_log($pdo, 'Update',
                    "Customer updated: {$name} (ID #{$cid})" . ($id_type ? " | ID Type: {$id_type}" : '') . " | Credit Limit: ₱" . number_format($credit, 2),
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

// ── Section data ──────────────────────────────────────────────────────────────
$balance_customers = [];

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

$section_meta = [
    'records'    => ['fas fa-users',         'Customer Records'],
    'balances'   => ['fas fa-wallet',        'Balances Monitoring'],
    'validation' => ['fas fa-user-shield',   'Validation & Oversight'],
    'transactions'=>['fas fa-receipt',       'Customer Transactions Oversight'],
];
[$sec_ico, $sec_title] = $section_meta[$section];

// ── Data for records section ───────────────────────────────────────────────────
$records_customers = [];
$edit_customer     = null;
if ($section === 'records') {
    try {
        $rc_avail   = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $rc_contact = in_array('contact_number', $rc_avail) ? 'contact_number' : "'' AS contact_number";
        $rc_id_type = in_array('id_type',        $rc_avail) ? 'id_type'        : "'' AS id_type";
        $rc_id_img  = in_array('id_image',       $rc_avail) ? 'id_image'       : "'' AS id_image";
        $rc_cr_img  = in_array('cr_image',       $rc_avail) ? 'cr_image'       : "'' AS cr_image";
        $rc_balance = in_array('balance',        $rc_avail) ? 'balance'        : "0 AS balance";
        $rc_credit  = in_array('credit_limit',   $rc_avail) ? 'credit_limit'   : "0 AS credit_limit";
        $rc_status  = in_array('status',         $rc_avail) ? 'status'         : "'active' AS status";
        $s = $pdo->prepare("SELECT id, name, $rc_contact, $rc_id_type, $rc_id_img, $rc_cr_img, $rc_credit, $rc_balance, $rc_status FROM customers WHERE station_id=? ORDER BY name");
        $s->execute([$station_id]);
        $records_customers = $s->fetchAll(PDO::FETCH_ASSOC);
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
.mgrc-card{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;}
.mgrc-head{padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.mgrc-title{font-size:16px;font-weight:700;color:#002F70;margin:0;display:flex;align-items:center;gap:8px;}
.mgrc-body{padding:20px;}
.mgrc-table{width:100%;border-collapse:collapse;font-size:13px;}
.mgrc-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#495057;border-bottom:2px solid #dee2e6;}
.mgrc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.mgrc-table tr:hover td{background:#f8f9fa;}
.badge-pending{background:#002F70;color:#fff;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
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

<!-- ===== SECTION: CUSTOMER RECORDS ===== -->
<?php if ($section === 'records'): ?>
<style>
.upd-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:600px){.upd-form-grid{grid-template-columns:1fr;}}
.upd-label{display:block;font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.upd-input{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.upd-input:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
</style>

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
    <form method="POST" action="manager_customers.php?section=records" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_customer">
      <input type="hidden" name="customer_id" value="<?php echo (int)$edit_customer['id']; ?>">
      <div class="upd-form-grid">
        <?php 
          $name_parts = explode(' ', $edit_customer['name'], 2);
          $fname = $name_parts[0] ?? '';
          $lname = $name_parts[1] ?? '';
        ?>
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
          <label class="upd-label">Type of ID</label>
          <select name="id_type" class="upd-input">
            <option value="">— Select ID Type —</option>
            <?php foreach ($gov_id_types as $idt): ?>
            <option value="<?php echo htmlspecialchars($idt); ?>"
              <?php echo ($edit_customer['id_type'] ?? '') === $idt ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($idt); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="upd-label">Upload Selected ID (Front/Copy)</label>
          <?php if (!empty($edit_customer['id_image'])): ?>
          <div style="margin-bottom:6px;font-size:12px;color:#6c757d;">
            <i class="fas fa-paperclip"></i> Current:
            <a href="../<?php echo htmlspecialchars($edit_customer['id_image']); ?>" target="_blank" style="color:#002F70;">View uploaded ID</a>
          </div>
          <?php endif; ?>
          <input type="file" name="id_image" class="upd-input" accept="image/*,.pdf" style="padding:6px 10px;">
          <span style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">Leave blank to keep existing. JPG, PNG, PDF accepted.</span>
        </div>
        <div>
          <label class="upd-label">Credit Limit (₱)</label>
          <input type="number" name="credit_limit" class="upd-input" value="<?php echo (float)$edit_customer['credit_limit']; ?>" min="0" step="0.01">
        </div>
      </div>
      <!-- CR / Certificate of Registration -->
      <div style="margin-top:18px;padding:16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;">
        <label class="upd-label" style="font-size:13px;color:#002F70;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <i class="fas fa-file-alt"></i> CR / Certificate of Registration
        </label>
        <?php if (!empty($edit_customer['cr_image'])): ?>
        <div style="margin-bottom:6px;font-size:12px;color:#6c757d;">
          <i class="fas fa-paperclip"></i> Current:
          <a href="../<?php echo htmlspecialchars($edit_customer['cr_image']); ?>" target="_blank" style="color:#002F70;">View uploaded CR</a>
        </div>
        <?php endif; ?>
        <input type="file" name="cr_image" class="upd-input" accept="image/*,.pdf" style="padding:6px 10px;background:#fff;">
        <span style="font-size:11px;color:#9ca3af;margin-top:4px;display:block;">Leave blank to keep existing. JPG, PNG, PDF accepted.</span>
      </div>
      <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;">
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
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-list"></i> Customer Directory</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($records_customers); ?> customers</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="recordSearch" placeholder="&#128269; Search customers..." oninput="filterRows('recordSearch','recordTable')">
    <div style="overflow-x:auto;">
      <table class="mgrc-table" id="recordTable">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Contact</th><th>ID Type</th><th>Credit Limit</th><th>Remaining Balance</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($records_customers)): ?>
          <tr><td colspan="8" class="mgrc-empty"><i class="fas fa-users"></i>No customers yet.</td></tr>
        <?php else: foreach ($records_customers as $c): ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
            <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$c['id']; ?></td>
            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
            <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($c['id_type'] ?? '—'); ?></td>
            <td>₱<?php echo number_format((float)$c['credit_limit'], 2); ?></td>
            <?php
              $enc_remaining = (float)$c['credit_limit'] - (float)$c['balance'];
              $enc_color = $enc_remaining <= 0 ? '#dc3545' : ($enc_remaining < (float)$c['credit_limit'] * 0.2 ? '#e67e22' : '#28a745');
            ?>
            <td style="color:<?php echo $enc_color; ?>;font-weight:700;">
              ₱<?php echo number_format($enc_remaining, 2); ?>
            </td>
            <td><span class="badge-<?php echo $c['status']==='active'?'active':'inactive'; ?>"><?php echo htmlspecialchars($c['status']); ?></span></td>
            <td>
              <a href="manager_customers.php?section=records&customer_id=<?php echo (int)$c['id']; ?>"
                 class="mgrc-btn mgrc-btn-view" style="font-size:11px;">
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
    <h2 class="mgrc-title"><i class="fas fa-wallet"></i> Customer Balances</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($balance_customers); ?> customers</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="balSearch" placeholder="&#128269; Search customers..." oninput="filterRows('balSearch', 'balTable')">
    <?php if (empty($balance_customers)): ?>
      <div class="mgrc-empty"><i class="fas fa-wallet"></i><strong>No customers found</strong></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="mgrc-table" id="balTable">
        <thead>
          <tr>
            <th>Name</th>
            <th>Contact</th>
            <th>Credit Limit</th>
            <th>Amount Used</th>
            <th>Remaining Balance</th>
            <th>Utilization</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($balance_customers as $c):
          $bal = (float)($c['balance'] ?? 0);
          $lim = (float)($c['credit_limit'] ?? 0);
          $remaining_credit = $lim - $bal;
          $avail_credit = max(0, $remaining_credit);
          $pct = $lim > 0 ? min(100, round($bal / $lim * 100)) : 0;
          $color = $remaining_credit <= 0 ? '#dc3545' : ($pct >= 80 ? '#e67e22' : '#28a745');
          $st = strtolower($c['status'] ?? 'active');
        ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
            <td>&#8369;<?php echo number_format($lim, 2); ?></td>
            <td style="color:<?php echo $bal>0?'#dc3545':'#6c757d'; ?>">&#8369;<?php echo number_format($bal, 2); ?></td>
            <td style="color:<?php echo $color; ?>;font-weight:700;">&#8369;<?php echo number_format($avail_credit, 2); ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="background:#e9ecef;border-radius:4px;height:6px;width:60px;" title="<?php echo $pct; ?>% used">
                  <div style="background:<?php echo $color; ?>;height:6px;border-radius:4px;width:<?php echo $pct; ?>%;"></div>
                </div>
                <span style="font-size:11px;color:#6c757d;"><?php echo $pct; ?>%</span>
              </div>
            </td>
            <td><span class="badge-<?php echo $st; ?>"><?php echo ucfirst($st); ?></span></td>
            <td>
              <a href="manager_customers.php?section=transactions&customer_id=<?php echo (int)$c['id']; ?>" class="mgrc-btn mgrc-btn-view" style="font-size:11px;padding:4px 10px;">
                <i class="fas fa-receipt"></i> Transactions
              </a>
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
              <button class="mgrc-btn mgrc-btn-approve" onclick="openModal('validate', <?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['name'])); ?>', 'approved')">Approve</button>
              <button class="mgrc-btn mgrc-btn-reject" onclick="openModal('validate', <?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['name'])); ?>', 'rejected')">Reject</button>
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
              <button class="mgrc-btn mgrc-btn-approve" onclick="openModal('review', <?php echo $r['id']; ?>, 'Update for <?php echo addslashes(htmlspecialchars($r['customer_name'])); ?>', 'approved')">Approve</button>
              <button class="mgrc-btn mgrc-btn-reject" onclick="openModal('review', <?php echo $r['id']; ?>, 'Update for <?php echo addslashes(htmlspecialchars($r['customer_name'])); ?>', 'rejected')">Reject</button>
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
</script>

<div style="height: 80px;"></div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
