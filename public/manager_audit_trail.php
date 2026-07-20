<?php
/**
 * Manager Audit Trail
 * Shows ONLY this manager's own validation actions: Approve, Reject, Adjust.
 * Columns: TXN ID, Customer, Type, Vehicle, Items/Parts, Service, Amount, Payment, Status, Date, Staff.
 * Visible to: manager + admin (admin can see all managers at their station).
 */
$page_id = 'mgr_audit_trail';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = (int) user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php'); exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from   = $_GET['date_from']   ?? date('Y-m-d', strtotime('-30 days'));
$date_to     = $_GET['date_to']     ?? date('Y-m-d');
$action_f    = trim($_GET['action_f']    ?? '');
$search      = trim($_GET['search']      ?? '');
$staff_f     = (int)($_GET['staff_f']   ?? 0); // admin can filter by staff

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ── For admin: list of all managers at this station ───────────────────────────
$manager_list = [];
if (in_array($role, ['admin', 'superadmin'])) {
    try {
        $ml = $pdo->prepare("SELECT id, name FROM users WHERE station_id=? AND LOWER(TRIM(role)) IN ('manager','supervisor') AND status='Active' ORDER BY name");
        $ml->execute([$station_id]);
        $manager_list = $ml->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Build query — manager sees own actions, admin sees all station managers ──
$allowed_actions = ['Approve','Reject','Adjust','Return','Admin_Approve','Admin_Return','Admin_Adjust',
                    'Approve Transaction','Return Transaction','Adjust Transaction',
                    'JO_APPROVED','JO_REJECTED'];
$action_placeholders = implode(',', array_fill(0, count($allowed_actions), '?'));

// Source 1: audit_logs (richest data — has entity details)
$al_where  = "WHERE u.station_id = ? AND DATE(al.created_at) BETWEEN ? AND ?
               AND al.action_type IN ($action_placeholders)";
$al_params = array_merge([$station_id, $date_from, $date_to], $allowed_actions);

if ($role === 'manager') {
    $al_where  .= " AND al.user_id = ?";
    $al_params[] = $me['id'];
} elseif ($staff_f > 0) {
    $al_where  .= " AND al.user_id = ?";
    $al_params[] = $staff_f;
}
if ($action_f !== '') { $al_where .= " AND al.action_type = ?"; $al_params[] = $action_f; }
if ($search  !== '') {
    $al_where .= " AND (al.action_details LIKE ? OR CAST(al.entity_id AS CHAR) LIKE ?)";
    $al_params[] = "%$search%"; $al_params[] = "%$search%";
}

$audit_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            al.id,
            al.created_at                                                  AS logged_at,
            al.user_id,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #', al.user_id))             AS actor_name,
            COALESCE(u.role, 'manager')                                    AS actor_role,
            al.action_type,
            UPPER(COALESCE(al.log_type,'TRANSACTION'))                     AS log_type,
            COALESCE(al.entity_type, 'merchandise_transactions')           AS entity_type,
            al.entity_id,
            COALESCE(al.action_details,'')                                 AS details,
            COALESCE(al.status,'SUCCESS')                                  AS status,
            -- Fetch transaction details for the required columns
            COALESCE(mt.customer_name, jo.customer_name, '')               AS customer,
            COALESCE(mt.job_order_vehicle_plate, jo.vehicle_plate, '')     AS vehicle,
            COALESCE(mt.job_order_vehicle_type, jo.vehicle_type, '')       AS vehicle_type,
            COALESCE(mt.job_order_service, jo.service_type, '')            AS service_type,
            COALESCE(mt.total_amount, jo.total_cost, jo.estimated_cost, 0) AS amount,
            COALESCE(mt.payment_method, jo.payment_method, '')             AS payment_method,
            COALESCE(mt.validation_status, jo.validation_status, '')       AS validation_status,
            COALESCE(
                NULLIF((SELECT GROUP_CONCAT(i.product_name,' x',i.quantity ORDER BY i.id SEPARATOR ' | ')
                        FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id
                        AND COALESCE(i.item_type,'merchandise')='merchandise'), ''),
                jo.required_parts, ''
            )                                                               AS items_parts,
            -- Staff who encoded the original transaction
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(staff_u.first_name,''),' ',COALESCE(staff_u.last_name,''))),''),
                     staff_u.username, '')                                  AS encoder_name
        FROM audit_logs al
        LEFT JOIN users u     ON u.id    = al.user_id
        LEFT JOIN merchandise_transactions mt ON mt.id = al.entity_id
                                              AND al.entity_type IN ('merchandise_transactions','transaction')
        LEFT JOIN job_orders jo           ON jo.id = al.entity_id
                                         AND al.entity_type = 'job_orders'
        LEFT JOIN users staff_u           ON staff_u.id = COALESCE(mt.staff_id, jo.created_by)
        $al_where
        ORDER BY al.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($al_params);
    $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("manager_audit_trail error: " . $e->getMessage());
}

// ── Summary counts ────────────────────────────────────────────────────────────
$total     = count($audit_rows);
$n_approve = count(array_filter($audit_rows, fn($r) => str_contains(strtolower($r['action_type']),'approv')));
$n_reject  = count(array_filter($audit_rows, fn($r) => str_contains(strtolower($r['action_type']),'reject') || str_contains(strtolower($r['action_type']),'return')));
$n_adjust  = count(array_filter($audit_rows, fn($r) => str_contains(strtolower($r['action_type']),'adjust')));

// ── CSV export ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="manager_audit_trail_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID','Timestamp','Manager','Role','Action','TXN Ref','Customer','Vehicle','Items','Service','Amount','Payment','Val.Status','Staff Encoder','Details']);
    foreach ($audit_rows as $r) {
        fputcsv($out, [
            $r['id'], date('M d Y H:i:s', strtotime($r['logged_at'])), $r['actor_name'],
            $r['actor_role'], $r['action_type'],
            ($r['entity_type'] === 'job_orders' ? 'JO-' : 'TXN-') . $r['entity_id'],
            $r['customer'], trim($r['vehicle'] . ' ' . $r['vehicle_type']),
            $r['items_parts'], $r['service_type'],
            number_format((float)$r['amount'],2), $r['payment_method'],
            $r['validation_status'], $r['encoder_name'], $r['details']
        ]);
    }
    fclose($out); exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.mat-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.mat-head{background:#002F70;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.mat-head h3{color:#fff;font-size:14px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;}
.mat-body{padding:16px 20px;}
.mat-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;}
.mat-kpi-card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:14px 16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.mat-kpi-num{font-size:24px;font-weight:800;line-height:1;}
.mat-kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;font-weight:600;}
.mat-filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
.mat-flt-g{display:flex;flex-direction:column;gap:4px;}
.mat-flt-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.mat-inp{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;}
.mat-inp:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
.mat-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;background:#fff;text-decoration:none;transition:all .15s;}
.mat-btn-blue{color:#002F70;border-color:#002F70;}.mat-btn-blue:hover{background:#002F70;color:#fff;}
.mat-btn-gray{color:#4b5563;border-color:#6b7280;}.mat-btn-gray:hover{background:#6b7280;color:#fff;}
.mat-btn-green{color:#16a34a;border-color:#16a34a;}.mat-btn-green:hover{background:#16a34a;color:#fff;}
.mat-table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed;}
.mat-table thead th{background:#002F70;color:#fff;padding:9px 6px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid #001a3d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.mat-table tbody td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;color:#1e293b;max-width:0;}
.mat-table tbody tr:hover td{background:#f0f7ff;}
.mat-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;}
.mat-badge-approve{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.mat-badge-reject{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.mat-badge-adjust{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;}
.mat-badge-other{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.mat-notice{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:12px;color:#1d4ed8;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
</style>

<!-- Page header -->
<div class="page-head" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
    <div>
        <h1 class="h1" style="margin:0;"><i class="fas fa-shield-alt"></i> Manager Audit Trail</h1>
        <div class="sub">
            <?php if (in_array($role, ['admin','superadmin'])): ?>
            All manager validation actions at this station — read-only compliance log.
            <?php else: ?>
            Your personal validation log — read-only compliance record.
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="mat-btn mat-btn-green">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <a href="<?= in_array($role,['admin','superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php' ?>" class="mat-btn mat-btn-gray">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- KPI strip -->
<div class="mat-kpi">
    <div class="mat-kpi-card">
        <div class="mat-kpi-num" style="color:#002F70;"><?= $total ?></div>
        <div class="mat-kpi-lbl">Total Actions</div>
    </div>
    <div class="mat-kpi-card">
        <div class="mat-kpi-num" style="color:#16a34a;"><?= $n_approve ?></div>
        <div class="mat-kpi-lbl">Approved</div>
    </div>
    <div class="mat-kpi-card">
        <div class="mat-kpi-num" style="color:#dc2626;"><?= $n_reject ?></div>
        <div class="mat-kpi-lbl">Rejected</div>
    </div>
    <div class="mat-kpi-card">
        <div class="mat-kpi-num" style="color:#1d4ed8;"><?= $n_adjust ?></div>
        <div class="mat-kpi-lbl">Adjusted</div>
    </div>
</div>

<!-- Notice -->
<div class="mat-notice">
    <i class="fas fa-lock"></i>
    <span><strong>Read-only.</strong> All validation actions are automatically logged with manager ID and timestamp. Records are immutable — cannot be edited or deleted.</span>
</div>

<!-- Filter Bar -->
<div class="mat-card">
    <div class="mat-head"><h3><i class="fas fa-filter"></i> Filters</h3></div>
    <div class="mat-body">
        <form method="get" class="mat-filter-row">
            <div class="mat-flt-g"><label class="mat-flt-lbl">Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="mat-inp" max="<?= date('Y-m-d') ?>"></div>
            <div class="mat-flt-g"><label class="mat-flt-lbl">Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="mat-inp" max="<?= date('Y-m-d') ?>"></div>
            <div class="mat-flt-g"><label class="mat-flt-lbl">Action</label>
                <select name="action_f" class="mat-inp" style="width:140px;">
                    <option value="">All Actions</option>
                    <?php foreach (['Approve','Reject','Adjust','Return'] as $a): ?>
                    <option value="<?= $a ?>" <?= $action_f===$a?'selected':'' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($manager_list)): ?>
            <div class="mat-flt-g"><label class="mat-flt-lbl">Manager</label>
                <select name="staff_f" class="mat-inp" style="width:160px;">
                    <option value="0">All Managers</option>
                    <?php foreach ($manager_list as $ml): ?>
                    <option value="<?= (int)$ml['id'] ?>" <?= $staff_f===$ml['id']?'selected':'' ?>><?= htmlspecialchars($ml['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="mat-flt-g"><label class="mat-flt-lbl">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="mat-inp" placeholder="TXN ref, customer…" style="width:180px;"></div>
            <div class="mat-flt-g"><label class="mat-flt-lbl">&nbsp;</label>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="mat-btn mat-btn-blue"><i class="fas fa-search"></i> Search</button>
                    <a href="manager_audit_trail.php" class="mat-btn mat-btn-gray"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Audit Table -->
<div class="mat-card">
    <div class="mat-head">
        <h3><i class="fas fa-list-alt"></i> Validation Log (<?= $total ?> record<?= $total!==1?'s':'' ?>)</h3>
    </div>
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="mat-table">
        <colgroup>
            <col style="width:5%;">  <!-- Log ID -->
            <col style="width:9%;">  <!-- Timestamp -->
            <col style="width:8%;">  <!-- Manager -->
            <col style="width:7%;">  <!-- Action -->
            <col style="width:6%;">  <!-- TXN Ref -->
            <col style="width:8%;">  <!-- Customer -->
            <col style="width:5%;">  <!-- Type -->
            <col style="width:7%;">  <!-- Vehicle -->
            <col style="width:12%;">  <!-- Items/Parts -->
            <col style="width:8%;">  <!-- Service -->
            <col style="width:7%;">  <!-- Amount -->
            <col style="width:6%;">  <!-- Payment -->
            <col style="width:7%;">  <!-- Status -->
            <col style="width:7%;">  <!-- Staff Encoder -->
            <col style="width:8%;">  <!-- Details -->
        </colgroup>
        <thead>
            <tr>
                <th>ID</th><th>Timestamp</th><th>Manager</th><th>Action</th>
                <th>TXN Ref</th><th>Customer</th><th>Type</th><th>Vehicle</th>
                <th>Items / Parts</th><th>Service</th><th>Amount</th>
                <th>Payment</th><th>Status</th><th>Encoder</th><th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($audit_rows)): ?>
        <tr><td colspan="15" style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-clipboard-list" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
            No audit records found for the selected filters.
        </td></tr>
        <?php else: foreach ($audit_rows as $r):
            $act_lower = strtolower($r['action_type'] ?? '');
            if (str_contains($act_lower,'approv'))     { $bc='mat-badge-approve'; $icon='✓'; }
            elseif (str_contains($act_lower,'reject')
                 || str_contains($act_lower,'return')) { $bc='mat-badge-reject';  $icon='✗'; }
            elseif (str_contains($act_lower,'adjust')) { $bc='mat-badge-adjust';  $icon='✎'; }
            else                                       { $bc='mat-badge-other';   $icon='·'; }
            $txn_ref = ($r['entity_type'] === 'job_orders' ? 'JO-' : 'TXN-') . $r['entity_id'];
            $vehicle = trim(($r['vehicle'] ?? '') . ' ' . ($r['vehicle_type'] ?? ''));
        ?>
        <tr>
            <td style="font-size:10px;color:#94a3b8;font-family:monospace;">#<?= $r['id'] ?></td>
            <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                <?= date('M j, Y', strtotime($r['logged_at'])) ?><br>
                <span style="font-size:10px;"><?= date('H:i:s', strtotime($r['logged_at'])) ?></span>
            </td>
            <td style="font-weight:600;" title="<?= htmlspecialchars($r['actor_name']) ?>">
                <?= htmlspecialchars(mb_strimwidth($r['actor_name'],0,14,'…')) ?>
            </td>
            <td><span class="mat-badge <?= $bc ?>"><?= $icon ?> <?= htmlspecialchars($r['action_type']) ?></span></td>
            <td style="font-family:monospace;font-size:11px;color:#002F70;font-weight:700;"><?= htmlspecialchars($txn_ref) ?></td>
            <td title="<?= htmlspecialchars($r['customer']) ?>"><?= htmlspecialchars(mb_strimwidth($r['customer'],0,14,'…')) ?></td>
            <td style="font-size:10px;color:#64748b;">
                <?php
                $et = $r['entity_type'] ?? '';
                echo $et === 'job_orders' ? 'Job Order' : 'Merchandise';
                ?>
            </td>
            <td title="<?= htmlspecialchars($vehicle) ?>"><?= htmlspecialchars(mb_strimwidth($vehicle,0,12,'…')) ?: '—' ?></td>
            <td title="<?= htmlspecialchars($r['items_parts']) ?>"><?= htmlspecialchars(mb_strimwidth($r['items_parts'],0,30,'…')) ?: '—' ?></td>
            <td title="<?= htmlspecialchars($r['service_type']) ?>"><?= htmlspecialchars(mb_strimwidth($r['service_type'],0,18,'…')) ?: '—' ?></td>
            <td style="font-weight:700;color:#002F70;">₱<?= number_format((float)$r['amount'],2) ?></td>
            <td><?= htmlspecialchars($r['payment_method']) ?: '—' ?></td>
            <td><span style="font-size:10px;color:#475569;"><?= htmlspecialchars($r['validation_status']) ?: '—' ?></span></td>
            <td style="font-size:11px;color:#64748b;" title="<?= htmlspecialchars($r['encoder_name']) ?>">
                <?= htmlspecialchars(mb_strimwidth($r['encoder_name'],0,12,'…')) ?: '—' ?>
            </td>
            <td title="<?= htmlspecialchars($r['details']) ?>" style="font-size:10px;color:#64748b;">
                <?= htmlspecialchars(mb_strimwidth($r['details'],0,40,'…')) ?: '—' ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>
<div style="height:60px;"></div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
