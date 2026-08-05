<?php
/**
 * Voided Transactions
 * Manage cancelled or invalid transactions
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_voided_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = (int) user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

$_SESSION['success'] = 'Voiding and transaction review are now handled inside All Transactions.';
header('Location: manager_validated_transactions.php');
exit;

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// â”€â”€ Create voided_transactions table if not exists â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voided_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(50) NOT NULL,
            transaction_type ENUM('job_order', 'merchandise', 'combined') NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            void_reason VARCHAR(255) NOT NULL,
            manager_remarks TEXT,
            voided_by INT NOT NULL,
            void_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            station_id INT NOT NULL,
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_void_date (void_date),
            INDEX idx_station_id (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log("Table creation: " . $e->getMessage());
}
// Ensure fields_changed column exists (added in later version)
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS fields_changed JSON DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS voided_by_name VARCHAR(255) DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS job_order_no VARCHAR(100) DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(50) DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL"); } catch(Exception $e2){}

// â”€â”€ POST handler removed: voiding now goes through /backend/api/void_transaction_manager.php via AJAX â”€

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_staff = $_GET['staff'] ?? '';

$where = ["vt.station_id = ?"];
$params = [$station_id];
if ($date_from !== '') {
    $where[] = "DATE(vt.void_date) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(vt.void_date) <= ?";
    $params[] = $date_to;
}
if ($filter_staff !== '') {
    $where[] = "vt.voided_by = ?";
    $params[] = $filter_staff;
}

// â”€â”€ Fetch KPI Data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi = ['total' => 0, 'today' => 0, 'amount' => 0.00];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_voids,
            SUM(CASE WHEN DATE(void_date) = CURDATE() THEN 1 ELSE 0 END) AS today_voids,
            SUM(amount) AS total_voided_amount
        FROM voided_transactions vt
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $kpi['total'] = (int)$row['total_voids'];
        $kpi['today'] = (int)$row['today_voids'];
        $kpi['amount'] = (float)$row['total_voided_amount'];
    }
} catch (Exception $e) {
    error_log("KPI error: " . $e->getMessage());
}

// â”€â”€ Fetch Voided Records â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$voided = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            vt.id, vt.transaction_id, vt.transaction_type, vt.customer_name,
            vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
            vt.fields_changed,
            COALESCE(NULLIF(vt.job_order_no,''), NULLIF(mt.job_order_id,'')) AS job_order_no,
            COALESCE(NULLIF(vt.vehicle_plate,''), NULLIF(mt.job_order_vehicle_plate,'')) AS vehicle_plate,
            COALESCE(NULLIF(vt.payment_method,''), NULLIF(mt.payment_method,''), 'Cash') AS payment_method,
            COALESCE(
                NULLIF(vt.voided_by_name,''),
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),
                u.username, 'Manager'
            ) AS voided_by_name,
            (SELECT GROUP_CONCAT(mti.product_name SEPARATOR ', ')
             FROM merchandise_transactions mt2
             INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt2.id
             WHERE mt2.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
            ) AS item_names
        FROM voided_transactions vt
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users u ON u.id = vt.voided_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY vt.void_date DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $voided = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Voided fetch error: " . $e->getMessage());
}

// â”€â”€ Pre-fetch items for voided transactions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$void_items_map = [];
try {
    if (!empty($voided)) {
        $void_txn_ids = array_unique(array_column($voided, 'transaction_id'));
        $void_txn_ids_str = implode("','", array_map(function($id) {
            return str_replace("'", "''", $id);
        }, $void_txn_ids));
        
        // Fetch items from merchandise_transaction_items
        $void_stmt = $pdo->query("
            SELECT mt.transaction_id AS txn_id, mti.product_name, mti.quantity, mti.unit_price, mti.subtotal,
                   COALESCE(mti.item_type,'merchandise') AS item_type
            FROM merchandise_transactions mt
            INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
            WHERE mt.transaction_id IN ('$void_txn_ids_str')
            ORDER BY mt.transaction_id, mti.id ASC
        ");
        foreach ($void_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $void_items_map[$item['txn_id']][] = $item;
        }
    }
} catch (Exception $e) { 
    $void_items_map = []; 
}

// â”€â”€ Fetch active transactions for voiding â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$active_transactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            mt.id,
            mt.transaction_id,
            mt.customer_name,
            mt.total_amount,
            mt.transaction_type,
            COALESCE(mt.transaction_date, mt.created_at) AS txn_date,
            u.name AS staff_name
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        WHERE mt.station_id = ?
          AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('voided')
        ORDER BY mt.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $active_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Staff list for filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$staff_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT id,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))),''), NULLIF(name,''), username) AS name
        FROM users
        WHERE station_id = ? AND role IN ('manager','supervisor','admin')
        ORDER BY name
    ");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$export = $_GET['export'] ?? '';
if (in_array($export, ['excel', 'csv'])) {
    $fn = 'voided_transactions_' . date('Ymd_His');
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment; filename=\"{$fn}.xls\"");
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$fn}.csv\"");
    }
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Void ID', 'Transaction ID', 'Job Order No.', 'Customer Name', 'Vehicle Plate No.', 'Transaction Type', 'Items/Service', 'Original Amount', 'Payment Method', 'Void Reason', 'Voided By', 'Void Date & Time', 'Status']);
    
    foreach ($voided as $v) {
        // Get items summary
        $items_summary = '';
        $v_fields = !empty($v['fields_changed']) ? json_decode($v['fields_changed'], true) : null;
        if (!empty($v_fields['voided_items'])) {
            $items_list = array_map(function($item) {
                return $item['product_name'] . ' (x' . $item['quantity'] . ')';
            }, $v_fields['voided_items']);
            $items_summary = implode(', ', $items_list);
        } else {
            $items_summary = 'Items not available';
        }
        
        fputcsv($out, [
            'VOID-' . $v['id'],
            $v['transaction_id'],
            $v['job_order_no'] ?? '—',
            $v['customer_name'] ?? 'Walk-in Customer',
            $v['vehicle_plate'] ?? '—',
            ucwords(str_replace('_', ' ', $v['transaction_type'])),
            $items_summary,
            '₱' . number_format($v['amount'], 2),
            $v['payment_method'] ?? 'N/A',
            $v['void_reason'],
            $v['voided_by_name'] ?? 'Manager',
            date('M d, Y h:i A', strtotime($v['void_date'])),
            'VOIDED'
        ]);
    }
    
    fclose($out);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - matches SuperAdmin page-head standard == */
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:16px !important; }
.page-head.txn-page-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.page-head.txn-page-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; font-weight:400 !important; }

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #00264D !important; border-color: #cbd5e1 !important; }
.flt-btn-excel:hover  { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf    { color: #00264D !important; border-color: #cbd5e1 !important; }
.flt-btn-pdf:hover    { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* Solid action buttons for forms and modals */
.flt-btn-solid-primary { color: #fff !important; background: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-solid-primary:hover { background: #001a3d !important; border-color: #001a3d !important; }
.flt-btn-solid-danger { color: #fff !important; background: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-solid-danger:hover { background: #b91c1c !important; border-color: #b91c1c !important; }

/* == TAB BUTTONS == */
.flt-btn {
    transition: all 0.2s ease-in-out;
}
.flt-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
.flt-btn-reset {
    background: #f1f5f9 !important;
    color: #64748b !important;
    border-color: #e2e8f0 !important;
}
.flt-btn-reset:hover {
    background: #e2e8f0 !important;
    color: #334155 !important;
}

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: transparent;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}

/* Special Gradient Card for Total Amount */
.txn-kpi-card.total-amount-card {
    background: transparent;
    border-left: 1px solid #e2e8f0;
}
.txn-kpi-card.total-amount-card .txn-kpi-lbl {
    color: #64748b;
}
.txn-kpi-card.total-amount-card .txn-kpi-val {
    color: #002F70;
}

/* == FILTERS == */
.filters { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.filters > div { display:flex; flex-direction:column; gap:3px; }
.filters label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.filters .input { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; color:#1e293b; background:#fff; outline:none; min-width:140px; }
.filters .input:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* == TABLE == */
.card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.card-head { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid #e9ecef; background:#f8fafc; }
.card-title { font-size:13px; font-weight:700; color:#00264D; }

.void-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.void-table thead tr { background:#002F70; }
.void-table th { padding:8px 6px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; line-height:1.3; border:none; word-wrap:break-word; }
.void-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.void-table tbody tr:hover td { background:#fef2f2; }
.void-table tbody td { padding:8px 6px; color:#334155; vertical-align:middle; background:#fff; font-size:12px; line-height:1.4; word-wrap:break-word; overflow-wrap:break-word; border:none; border-bottom:1px solid #f1f5f9; }

/* == MODAL == */
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:9999; background:rgba(15,23,42,0.5); }

/* Item chips (same as All Transactions) */
.rc-item-chip{display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:3px;padding:1px 5px;font-size:9px;font-weight:600;color:#374151;margin:1px 2px 1px 0;white-space:nowrap;}
.rc-item-chip.svc{background:#fffbeb;border-color:#fde68a;color:#92400e;}
.rc-item-chip .rc-chip-qty{background:#002F70;color:#fff;border-radius:2px;padding:0 3px;font-size:8px;margin-left:2px;}
.modal-card { position:relative; background:#fff; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,.1); }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#dc2626; color:#fff; }
.modal-title { font-weight:700; font-size:15px; }
.modal-close { background:none; border:none; color:#fff; font-size:20px; cursor:pointer; }
.modal-body { padding:20px; overflow-y:auto; }
.modal-body label { font-size:11px; font-weight:600; color:#475569; text-transform:uppercase; display:block; margin-bottom:4px; }
.modal-body .input { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; }
@media print {
    .action-bar, .sidebar, .main-sidebar, .navbar, .filters, form, .flt-btn, .modal, button, .actions, .page-head.txn-page-head, .card-head div { display:none!important; }
    body { background:#fff; margin:0; padding:10px; }
    .card { border:none; box-shadow:none; margin-top:10px !important; }
    table { width:100%!important; font-size:10px; }
}
</style>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><i class="fas fa-ban"></i> Voided Transactions History</h1>
        <div class="sub">Review and monitor voided, cancelled, and reversed transactions.</div>
    </div>
    <div class="actions txn-head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="javascript:history.back()" class="flt-btn flt-btn-reset" style="text-decoration:none;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-ban"></i> Total Voided Transactions</div>
        <div class="txn-kpi-val"><?php echo $kpi['total']; ?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-calendar-day"></i> Voided Today</div>
        <div class="txn-kpi-val"><?php echo $kpi['today']; ?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Voided Amount</div>
        <div class="txn-kpi-val">₱<?php echo number_format($kpi['amount'], 2); ?></div>
    </div>
</div>

<!-- FILTERS -->
<div class="card">
    <form method="GET" class="filters">
        <div>
            <label>Date From <span style="font-weight:400;font-size:10px;color:#94a3b8;">(Optional)</span></label>
            <input type="date" name="date_from" class="input" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="All dates">
        </div>
        <div>
            <label>Date To <span style="font-weight:400;font-size:10px;color:#94a3b8;">(Optional)</span></label>
            <input type="date" name="date_to" class="input" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="All dates">
        </div>
        <div>
            <label>Voided By</label>
            <select name="staff" class="input">
                <option value="">All Users</option>
                <?php foreach ($staff_list as $staff): ?>
                <option value="<?php echo $staff['id']; ?>" <?php echo $filter_staff == $staff['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($staff['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-filter"></i> Apply</button>
            <a href="voided_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- VOIDED TRANSACTIONS (Direct Display - No Tabs) -->
<div class="card" style="margin-top:0;">
    <div class="card-head">
        <div class="card-title">Voided Transactions History (<?php echo count($voided); ?>)</div>
    </div>
    <div style="width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="void-table">
        <thead>
            <tr>
                <th style="width:4.2%;">Void ID</th>
                <th style="width:8.5%;">Transaction ID</th>
                <th style="width:6.5%;">Job Order</th>
                <th style="width:9.5%;">Customer</th>
                <th style="width:6.5%;">Plate No.</th>
                <th style="width:6.5%;">Type</th>
                <th style="width:15%;">Items / Service</th>
                <th style="width:6.5%;">Amount</th>
                <th style="width:7.5%;">Payment</th>
                <th style="width:13%;">Void Reason</th>
                <th style="width:7.5%;">Voided By</th>
                <th style="width:9.5%;">Date & Time</th>
                <th style="width:5.5%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$voided): ?>
            <tr><td colspan="13" style="text-align:center;padding:40px;color:#888;">No voided transactions found</td></tr>
            <?php else: ?>
            <?php foreach ($voided as $v): 
                $v_fields  = !empty($v['fields_changed']) ? json_decode($v['fields_changed'], true) : [];
                $jo_raw    = !empty($v['job_order_no']) ? $v['job_order_no'] : ($v_fields['job_order_no'] ?? '');
                $jo_disp   = !empty($jo_raw) ? (str_starts_with($jo_raw, 'JO-') ? $jo_raw : 'JO-' . $jo_raw) : '—';
                $plate_disp = !empty($v['vehicle_plate']) ? $v['vehicle_plate'] : ($v_fields['vehicle_plate'] ?? '—');
                $payment   = !empty($v['payment_method']) ? $v['payment_method'] : ($v_fields['payment_method'] ?? 'Cash');
                if (empty($payment) || $payment === 'N/A') $payment = 'Cash';
            ?>
            <tr>
                <td><strong>#<?php echo $v['id']; ?></strong></td>
                <td><?php echo htmlspecialchars($v['transaction_id']); ?></td>
                <td><?php echo htmlspecialchars($jo_disp); ?></td>
                <td><?php echo htmlspecialchars($v['customer_name'] ?? 'Walk-in'); ?></td>
                <td><?php echo htmlspecialchars($plate_disp); ?></td>
                <td>
                    <?php 
                    $type = ucwords(str_replace('_', ' ', $v['transaction_type']));
                    $type_short = $type;
                    if (stripos($type, 'job') !== false) $type_short = 'Job Order';
                    elseif (stripos($type, 'merchandise') !== false) $type_short = 'Merch';
                    elseif (stripos($type, 'combined') !== false) $type_short = 'Combined';
                    $type_color = '#64748b';
                    if (stripos($type, 'job') !== false) $type_color = '#3b82f6';
                    elseif (stripos($type, 'merchandise') !== false) $type_color = '#10b981';
                    elseif (stripos($type, 'combined') !== false) $type_color = '#8b5cf6';
                    ?>
                    <span style="display:inline-block;padding:2px 4px;background:<?php echo $type_color; ?>1a;color:<?php echo $type_color; ?>;border-radius:3px;font-size:8px;font-weight:700;line-height:1.2;">
                        <?php echo $type_short; ?>
                    </span>
                </td>
                <td style="font-size:9px;line-height:1.3;">
                    <?php 
                    $txn_id   = $v['transaction_id'];
                    if (!empty($v_fields['voided_items'])) {
                        foreach ($v_fields['voided_items'] as $item) {
                            $qty = (float)($item['quantity'] ?? 1);
                            $sub = (float)($item['subtotal'] ?? 0);
                            echo '<div style="margin-bottom:2px;padding:2px 4px;border:1px solid #fca5a5;border-radius:3px;background:#fff5f5;font-size:8px;line-height:1.3;">';
                            echo '<strong>' . htmlspecialchars(substr($item['product_name'] ?? '', 0, 28)) . (strlen($item['product_name'] ?? '') > 28 ? '..' : '') . '</strong><br>';
                            echo '<span style="color:#64748b;">Qty: ' . $qty . ' | ₱' . number_format($sub, 2) . '</span>';
                            echo '</div>';
                        }
                    } elseif (!empty($void_items_map[$txn_id])) {
                        foreach ($void_items_map[$txn_id] as $item) {
                            $qty = (float)($item['quantity'] ?? 1);
                            $sub = (float)($item['subtotal'] ?? 0);
                            echo '<div style="margin-bottom:2px;padding:2px 4px;border:1px solid #cbd5e1;border-radius:3px;background:#f8fafc;font-size:8px;line-height:1.3;">';
                            echo '<strong>' . htmlspecialchars(substr($item['product_name'] ?? '', 0, 28)) . (strlen($item['product_name'] ?? '') > 28 ? '..' : '') . '</strong><br>';
                            echo '<span style="color:#64748b;">Qty: ' . $qty . ' | ₱' . number_format($sub, 2) . '</span>';
                            echo '</div>';
                        }
                    } elseif (!empty($v['item_names'])) {
                        echo '<span style="font-size:8px;color:#334155;">' . htmlspecialchars($v['item_names']) . '</span>';
                    } else {
                        echo '<span style="font-size:8px;color:#94a3b8;font-style:italic;">— (legacy record)</span>';
                    }
                    ?>
                </td>
                <td style="font-weight:700;color:#dc2626;">₱<?php echo number_format($v['amount'], 2); ?></td>
                <td>
                    <?php 
                    $payment_short = $payment;
                    if (stripos($payment, 'cash') !== false) $payment_short = 'Cash';
                    elseif (stripos($payment, 'credit') !== false || stripos($payment, 'card') !== false) $payment_short = 'Card';
                    elseif (stripos($payment, 'gcash') !== false) $payment_short = 'GCash';
                    elseif (stripos($payment, 'online') !== false) $payment_short = 'Online';
                    
                    $payment_color = '#64748b';
                    if (stripos($payment, 'cash') !== false) $payment_color = '#10b981';
                    elseif (stripos($payment, 'card') !== false || stripos($payment, 'credit') !== false) $payment_color = '#3b82f6';
                    elseif (stripos($payment, 'gcash') !== false || stripos($payment, 'online') !== false) $payment_color = '#f59e0b';
                    ?>
                    <span style="display:inline-block;padding:2px 4px;background:<?php echo $payment_color; ?>1a;color:<?php echo $payment_color; ?>;border-radius:3px;font-size:8px;font-weight:600;line-height:1.2;">
                        <?php echo htmlspecialchars($payment_short); ?>
                    </span>
                </td>
                <td><?php echo htmlspecialchars($v['void_reason']); ?></td>
                <td><?php echo htmlspecialchars($v['voided_by_name'] ?? 'Manager'); ?></td>
                <td><?php echo date('M d, Y', strtotime($v['void_date'])); ?><br><span style="color:#64748b;font-size:11px;"><?php echo date('h:i A', strtotime($v['void_date'])); ?></span></td>
                <td>
                    <span style="display:inline-block;padding:2px 4px;background:#fee2e2;color:#dc2626;border-radius:3px;font-size:8px;font-weight:700;line-height:1.2;">
                        VOID
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Voided Transaction Detail Modal - REMOVED (Action column removed, read-only history) -->

<script>
// Modal functions removed - voided transactions is now read-only history
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
