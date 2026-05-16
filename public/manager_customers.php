<?php
$page_id = 'mgr_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$valid_sections = ['encode','edit_customer','balances'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'encode';

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
            } catch (Exception $e) {
                $flash_err = 'Error saving customer: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'update_customer') {
        $cid     = (int)($_POST['customer_id'] ?? 0);
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
                header("Location: manager_customers.php?section=edit_customer&updated=1");
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
    'encode'        => ['fas fa-user-plus',  'Encode Customer Details'],
    'edit_customer' => ['fas fa-user-edit',  'Update Customer Details'],
    'balances'      => ['fas fa-wallet',     'Balances Monitoring'],
];
[$sec_ico, $sec_title] = $section_meta[$section];

// ── Data for edit_customer section ───────────────────────────────────────────
$edit_all_customers = [];
$edit_customer      = null;
if ($section === 'edit_customer') {
    try {
        $ec2_avail   = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $ec2_contact = in_array('contact_number', $ec2_avail) ? 'contact_number' : "'' AS contact_number";
        $ec2_id_type = in_array('id_type',        $ec2_avail) ? 'id_type'        : "'' AS id_type";
        $ec2_id_img  = in_array('id_image',       $ec2_avail) ? 'id_image'       : "'' AS id_image";
        $ec2_cr_img  = in_array('cr_image',       $ec2_avail) ? 'cr_image'       : "'' AS cr_image";
        $ec2_balance = in_array('balance',        $ec2_avail) ? 'balance'        : "0 AS balance";
        $ec2_credit  = in_array('credit_limit',   $ec2_avail) ? 'credit_limit'   : "0 AS credit_limit";
        $ec2_status  = in_array('status',         $ec2_avail) ? 'status'         : "'active' AS status";
        $s = $pdo->prepare("SELECT id, name, $ec2_contact, $ec2_id_type, $ec2_id_img, $ec2_cr_img, $ec2_credit, $ec2_balance, $ec2_status FROM customers WHERE station_id=? ORDER BY name");
        $s->execute([$station_id]);
        $edit_all_customers = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if (isset($_GET['customer_id'])) {
        $cid_get = (int)$_GET['customer_id'];
        foreach ($edit_all_customers as $ec) {
            if ($ec['id'] === $cid_get) { $edit_customer = $ec; break; }
        }
    }
    // Show success flash after redirect
    if (isset($_GET['updated'])) $flash_ok = "Customer updated successfully.";
}

// ── Customer list for encode section ─────────────────────────────────────────
$encode_customers = [];
if ($section === 'encode') {
    try {
        $ec_avail = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $ec_contact  = in_array('contact_number', $ec_avail) ? 'contact_number' : "'' AS contact_number";
        $ec_id_type  = in_array('id_type',        $ec_avail) ? 'id_type'        : "'' AS id_type";
        $ec_balance  = in_array('balance',        $ec_avail) ? 'balance'        : "0 AS balance";
        $ec_credit   = in_array('credit_limit',   $ec_avail) ? 'credit_limit'   : "0 AS credit_limit";
        $ec_status   = in_array('status',         $ec_avail) ? 'status'         : "'active' AS status";
        $s = $pdo->prepare("SELECT id, name, $ec_contact, $ec_id_type, $ec_credit, $ec_balance, $ec_status FROM customers WHERE station_id=? ORDER BY name");
        $s->execute([$station_id]);
        $encode_customers = $s->fetchAll(PDO::FETCH_ASSOC);
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

<!-- ===== SECTION: ENCODE CUSTOMER ===== -->
<?php if ($section === 'encode'): ?>
<style>
.enc-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:600px){.enc-form-grid{grid-template-columns:1fr;}}
.enc-label{display:block;font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.enc-input{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.enc-input:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
.enc-btn-primary{padding:10px 22px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:#002F70;color:#fff;transition:all .2s;}
.enc-btn-primary:hover{background:#0040a0;}
</style>

<!-- Add New Customer Form -->
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-plus"></i> Add New Customer</h2>
  </div>
  <div class="mgrc-body">
    <form method="POST" action="manager_customers.php?section=encode" enctype="multipart/form-data">
      <input type="hidden" name="action" value="encode_customer">
      <div class="enc-form-grid">
        <div>
          <label class="enc-label">Full Name <span style="color:red">*</span></label>
          <input type="text" name="name" class="enc-input" placeholder="Enter customer name" required>
        </div>
        <div>
          <label class="enc-label">Contact Number</label>
          <input type="text" name="contact" class="enc-input" placeholder="e.g. 09XX-XXX-XXXX">
        </div>
        <div>
          <label class="enc-label">Type of ID</label>
          <select name="id_type" class="enc-input">
            <option value="">— Select ID Type —</option>
            <?php foreach ($gov_id_types as $idt): ?>
            <option value="<?php echo htmlspecialchars($idt); ?>"><?php echo htmlspecialchars($idt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="enc-label">Upload Selected ID (Front/Copy)</label>
          <input type="file" name="id_image" class="enc-input" accept="image/*,.pdf" style="padding:6px 10px;">
          <span style="font-size:11px;color:#9ca3af;margin-top:3px;display:block;">JPG, PNG, PDF accepted</span>
        </div>
        <div>
          <label class="enc-label">Credit Limit (₱)</label>
          <input type="number" name="credit_limit" class="enc-input" placeholder="0.00" min="0" step="0.01" value="0">
        </div>
      </div>
      <!-- CR / Certificate of Registration -->
      <div style="margin-top:18px;padding:16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;">
        <label class="enc-label" style="font-size:13px;color:#002F70;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <i class="fas fa-file-alt"></i> CR / Certificate of Registration
        </label>
        <input type="file" name="cr_image" class="enc-input" accept="image/*,.pdf" style="padding:6px 10px;background:#fff;">
        <span style="font-size:11px;color:#9ca3af;margin-top:4px;display:block;">Upload the vehicle's Certificate of Registration (CR). JPG, PNG, PDF accepted.</span>
      </div>
      <div style="margin-top:18px;">
        <button type="submit" class="enc-btn-primary"><i class="fas fa-save"></i> Save Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- Customer List (view-only) -->
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-list"></i> Customer List</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($encode_customers); ?> customers</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="encodeSearch" placeholder="&#128269; Search customers..." oninput="filterRows('encodeSearch','encodeTable')">
    <div style="overflow-x:auto;">
      <table class="mgrc-table" id="encodeTable">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Contact</th><th>ID Type</th><th>Credit Limit</th><th>Remaining Balance</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php if (empty($encode_customers)): ?>
          <tr><td colspan="7" class="mgrc-empty"><i class="fas fa-users"></i>No customers yet.</td></tr>
        <?php else: foreach ($encode_customers as $c): ?>
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
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: UPDATE CUSTOMER DETAILS ===== -->
<?php if ($section === 'edit_customer'): ?>
<style>
.upd-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:600px){.upd-form-grid{grid-template-columns:1fr;}}
.upd-label{display:block;font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.upd-input{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.upd-input:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
</style>

<?php if (!$edit_customer): ?>
<!-- Customer picker list -->
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-edit"></i> Update Customer Details</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($edit_all_customers); ?> customers</span>
  </div>
  <div class="mgrc-body">
    <p style="color:#6c757d;font-size:13px;margin-bottom:14px;">Select a customer to edit:</p>
    <input class="mgrc-search" id="editSearch" placeholder="&#128269; Search customers..." oninput="filterRows('editSearch','editTable')">
    <div style="overflow-x:auto;">
      <table class="mgrc-table" id="editTable">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Contact</th><th>ID Type</th><th>Credit Limit</th><th>Remaining Balance</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($edit_all_customers)): ?>
          <tr><td colspan="8" class="mgrc-empty"><i class="fas fa-users"></i>No customers found.</td></tr>
        <?php else: foreach ($edit_all_customers as $c):
          $rem = (float)$c['credit_limit'] - (float)$c['balance'];
          $rem_color = $rem <= 0 ? '#dc3545' : ($rem < (float)$c['credit_limit'] * 0.2 ? '#e67e22' : '#28a745');
        ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
            <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$c['id']; ?></td>
            <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
            <td><?php echo htmlspecialchars($c['contact_number'] ?? '—'); ?></td>
            <td style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($c['id_type'] ?? '—'); ?></td>
            <td>₱<?php echo number_format((float)$c['credit_limit'], 2); ?></td>
            <td style="color:<?php echo $rem_color; ?>;font-weight:700;">₱<?php echo number_format($rem, 2); ?></td>
            <td><span class="badge-<?php echo $c['status']==='active'?'active':'inactive'; ?>"><?php echo htmlspecialchars($c['status']); ?></span></td>
            <td>
              <a href="manager_customers.php?section=edit_customer&customer_id=<?php echo (int)$c['id']; ?>"
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

<?php else: ?>
<!-- Edit form for selected customer -->
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-user-edit"></i> Editing: <?php echo htmlspecialchars($edit_customer['name']); ?></h2>
    <a href="manager_customers.php?section=edit_customer" class="mgrc-btn" style="background:#6c757d;color:#fff;font-size:12px;">
      <i class="fas fa-arrow-left"></i> Back to List
    </a>
  </div>
  <div class="mgrc-body">
    <form method="POST" action="manager_customers.php?section=edit_customer" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_customer">
      <input type="hidden" name="customer_id" value="<?php echo (int)$edit_customer['id']; ?>">
      <div class="upd-form-grid">
        <div>
          <label class="upd-label">Full Name <span style="color:red">*</span></label>
          <input type="text" name="name" class="upd-input" value="<?php echo htmlspecialchars($edit_customer['name']); ?>" required>
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
        <a href="manager_customers.php?section=edit_customer" class="mgrc-btn" style="background:#6c757d;color:#fff;padding:10px 18px;font-size:13px;text-decoration:none;">
          <i class="fas fa-times"></i> Cancel
        </a>
      </div>
    </form>
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
    <input class="mgrc-search" id="balSearch" placeholder="Search customers..." oninput="filterCards('balSearch')">
    <?php if (empty($balance_customers)): ?>
      <div class="mgrc-empty"><i class="fas fa-wallet"></i><strong>No customers found</strong></div>
    <?php else: ?>
    <div class="mgrc-bal-grid" id="balGrid">
      <?php foreach ($balance_customers as $c):
        $bal = (float)($c['balance'] ?? 0);
        $lim = (float)($c['credit_limit'] ?? 0);
        $remaining_credit = $lim - $bal;
        $avail_credit = max(0, $remaining_credit);
        $pct = $lim > 0 ? min(100, round($bal / $lim * 100)) : 0;
        $color = $remaining_credit <= 0 ? '#dc3545' : ($pct >= 80 ? '#e67e22' : '#28a745');
        $st = strtolower($c['status'] ?? 'active');
      ?>
      <div class="mgrc-bal-card" data-name="<?php echo strtolower(htmlspecialchars($c['name'])); ?>">
        <div class="mgrc-bal-name"><?php echo htmlspecialchars($c['name']); ?></div>
        <div style="font-size:11px;color:#6c757d;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px;">Remaining Balance</div>
        <div class="mgrc-bal-amount" style="color:<?php echo $color; ?>">
          &#8369;<?php echo number_format($avail_credit, 2); ?>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:6px;margin-bottom:10px;" title="<?php echo $pct; ?>% used">
          <div style="background:<?php echo $color; ?>;height:6px;border-radius:4px;width:<?php echo $pct; ?>%;transition:width .4s;"></div>
        </div>
        <div class="mgrc-bal-detail"><span>Credit Limit</span><span>&#8369;<?php echo number_format($lim,2); ?></span></div>
        <div class="mgrc-bal-detail"><span>Amount Used</span><span style="color:<?php echo $bal>0?'#dc3545':'#6c757d'; ?>">&#8369;<?php echo number_format($bal,2); ?></span></div>
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
