<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'mgr_job_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../config/database_config.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('job_orders')) {
    render_module_disabled_page('Job Orders');
}
$db_config  = require __DIR__ . '/../config/database_config.php';

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php'); exit;
}

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action  = $_POST['action'] ?? '';
    $job_id  = (int)($_POST['job_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    // Detect columns helper
    $getColMap = function() use ($pdo, $db_config) {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM {$db_config['job_orders']}")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $cols[strtolower($c['Field'])] = true;
        return $cols;
    };

    // ── Approve / Reject ──────────────────────────────────────────────────
    if ($action === 'approve_reject_job_order' && $job_id) {
        $approval   = $_POST['approval_action'] ?? '';
        $job_source = $_POST['job_source'] ?? 'job_orders';
        try {
            if ($job_source === 'merchandise_transactions') {
                // Record from staff_transactions_hub — lives in merchandise_transactions
                if ($approval === 'approve') {
                    $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Approved', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $job_id, $station_id]);
                    log_activity($pdo, $me['id'], 'JOB_ORDER_APPROVED', "Manager {$me['name']} approved job order #{$job_id} (merch_txn).");
                    $_SESSION['success'] = "Job order #{$job_id} approved.";
                } else {
                    $pdo->prepare("UPDATE merchandise_transactions SET validation_status='Rejected', validated_by=?, validated_at=NOW(), updated_at=NOW() WHERE id=? AND station_id=?")
                        ->execute([$me['id'], $job_id, $station_id]);
                    log_activity($pdo, $me['id'], 'JOB_ORDER_REJECTED', "Manager {$me['name']} rejected job order #{$job_id} (merch_txn).");
                    $_SESSION['success'] = "Job order #{$job_id} rejected.";
                }
            } else {
                $cols = $getColMap();
                $has  = fn($col) => isset($cols[$col]);
                if (!$has('validated_by')) $pdo->exec("ALTER TABLE {$db_config['job_orders']} ADD COLUMN validated_by INT NULL");
                if (!$has('validated_at')) $pdo->exec("ALTER TABLE {$db_config['job_orders']} ADD COLUMN validated_at DATETIME NULL");

                $stmt = $pdo->prepare("SELECT * FROM {$db_config['job_orders']} WHERE id=? AND station_id=?");
                $stmt->execute([$job_id, $station_id]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$job) throw new Exception('Job order not found.');

                if ($approval === 'approve') {
                    $set = ["validation_status='Approved'", "status='Pending'"]; $vals = [];
                    if ($has('validated_by')) { $set[] = "validated_by=?"; $vals[] = $me['id']; }
                    if ($has('validated_at')) { $set[] = "validated_at=NOW()"; }
                    if ($has('updated_at'))   { $set[] = "updated_at=NOW()"; }
                    $pdo->prepare("UPDATE {$db_config['job_orders']} SET ".implode(',',$set)." WHERE id=?")->execute(array_merge($vals,[$job_id]));
                    try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                        ->execute([$job_id,'APPROVE',$job['validation_status'],'Approved',$me['id'],"Approved by {$me['name']}".($remarks?" Remarks:$remarks":''),$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $e){}
                    log_activity($pdo,$me['id'],'JOB_ORDER_APPROVED',"Manager {$me['name']} approved job order #{$job_id}.");
                    $_SESSION['success'] = "Job order #{$job_id} approved.";
                } else {
                    $set = ["validation_status='Rejected'", "status='Cancelled'"]; $vals = [];
                    if ($has('validated_by')) { $set[] = "validated_by=?"; $vals[] = $me['id']; }
                    if ($has('validated_at')) { $set[] = "validated_at=NOW()"; }
                    if ($has('updated_at'))   { $set[] = "updated_at=NOW()"; }
                    $pdo->prepare("UPDATE {$db_config['job_orders']} SET ".implode(',',$set)." WHERE id=?")->execute(array_merge($vals,[$job_id]));
                    try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                        ->execute([$job_id,'REJECT',$job['validation_status'],'Rejected',$me['id'],"Rejected by {$me['name']}".($remarks?" Remarks:$remarks":''),$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $e){}
                    log_activity($pdo,$me['id'],'JOB_ORDER_REJECTED',"Manager {$me['name']} rejected job order #{$job_id}.");
                    $_SESSION['success'] = "Job order #{$job_id} rejected.";
                }
            }
        } catch (Exception $e) { $_SESSION['error'] = 'Error: '.$e->getMessage(); }
        header('Location: manager_job_orders.php'); exit;
    }

    // ── Adjust ────────────────────────────────────────────────────────────
    if ($action === 'adjust_job_order' && $job_id) {
        $new_cost    = (float)($_POST['adj_cost']    ?? 0);
        $new_remarks = trim($_POST['adj_remarks']    ?? '');
        $mgr_notes   = trim($_POST['adj_mgr_notes']  ?? '');
        if (!$mgr_notes) { $_SESSION['error'] = 'Manager notes are required for adjustment.'; header('Location: manager_job_orders.php'); exit; }
        try {
            $cols = $getColMap();
            $has  = fn($col) => isset($cols[$col]);
            $stmt = $pdo->prepare("SELECT * FROM {$db_config['job_orders']} WHERE id=? AND station_id=?");
            $stmt->execute([$job_id, $station_id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) throw new Exception('Job order not found.');

            $old_cost = (float)($job['estimated_cost'] ?? 0);
            $diff = [];
            if (abs($new_cost - $old_cost) > 0.001) $diff[] = "Cost: ₱".number_format($old_cost,2)." → ₱".number_format($new_cost,2);
            if ($new_remarks && $new_remarks !== ($job['notes'] ?? '')) $diff[] = "Remarks updated";
            $diff_summary = $diff ? implode(' | ',$diff) : 'No field changes.';

            $set = ["estimated_cost=?", "validation_status='Adjusted'"]; $vals = [$new_cost];
            if ($new_remarks !== '') { $set[] = "notes=?"; $vals[] = $new_remarks; }
            if ($has('validated_by')) { $set[] = "validated_by=?"; $vals[] = $me['id']; }
            if ($has('validated_at')) { $set[] = "validated_at=NOW()"; }
            if ($has('updated_at'))   { $set[] = "updated_at=NOW()"; }
            $pdo->prepare("UPDATE {$db_config['job_orders']} SET ".implode(',',$set)." WHERE id=?")->execute(array_merge($vals,[$job_id]));
            try { $pdo->prepare("INSERT INTO job_order_audit (job_order_id,action,before_status,after_status,performed_by,performed_at,notes,ip_address,user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                ->execute([$job_id,'ADJUST',$job['validation_status'],'Adjusted',$me['id'],"Adjusted by {$me['name']}. $diff_summary. Notes: $mgr_notes",$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']); } catch(Exception $e){}
            log_activity($pdo,$me['id'],'JOB_ORDER_ADJUSTED',"Manager {$me['name']} adjusted job order #{$job_id}. $diff_summary");
            $_SESSION['success'] = "Job order #{$job_id} adjusted. $diff_summary";
        } catch (Exception $e) { $_SESSION['error'] = 'Error: '.$e->getMessage(); }
        header('Location: manager_job_orders.php'); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────
$status_filter = trim($_GET['status'] ?? '');
$search_filter = trim($_GET['search'] ?? '');

// ── Stats ─────────────────────────────────────────────────────────────────
$jo_stats = ['total'=>0,'pending'=>0,'approved'=>0,'in_progress'=>0,'completed'=>0,'rejected'=>0];
try {
    $r = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status='Pending Validation' OR validation_status='Pending Validation' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status IN ('Approved','Validated') THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status IN ('Rejected','Cancelled') THEN 1 ELSE 0 END) AS rejected
        FROM job_orders WHERE station_id=?");
    $r->execute([$station_id]);
    $jo_stats = $r->fetch(PDO::FETCH_ASSOC) ?: $jo_stats;
} catch (Exception $e) {}

// ── Load rows ─────────────────────────────────────────────────────────────
$where = ["j.station_id=?"]; $params = [$station_id];
if ($status_filter !== '') { $where[] = "(j.status=? OR j.validation_status=?)"; $params[] = $status_filter; $params[] = $status_filter; }
if ($search_filter !== '') {
    $where[] = "(COALESCE(c.name,j.customer_name,'') LIKE ? OR j.service_type LIKE ? OR u.name LIKE ?)";
    $s = '%'.$search_filter.'%'; $params[] = $s; $params[] = $s; $params[] = $s;
}

$job_orders = [];
try {
    // ── Part 1: native job_orders rows ───────────────────────────────────────
    $jo_where_sql = implode(' AND ', $where);
    $part1 = "
        SELECT j.id, j.customer_name, j.service_type, j.service_description,
            j.status, j.validation_status, j.estimated_cost, j.notes, j.created_at,
            COALESCE(c.name, j.customer_name, 'Walk-in') AS cust, u.name AS staff_name,
            'job_orders' AS _source
        FROM job_orders j
        LEFT JOIN customers c ON c.id = j.customer_id
        LEFT JOIN users u ON u.user_id = COALESCE(j.created_by, j.user_id)
        WHERE {$jo_where_sql}
    ";

    // ── Part 2: merchandise_transactions with job_order/combined type ─────────
    $part2 = '';
    $mt_params2 = [];
    try {
        $mt_cols2 = [];
        foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC) as $c)
            $mt_cols2[strtolower($c['Field'])] = true;
        $mt_has2 = fn($col) => isset($mt_cols2[strtolower($col)]);

        if ($mt_has2('transaction_type') && $mt_has2('job_order_service')) {
            $mt2_where = ["mt2.station_id = ?", "mt2.transaction_type IN ('job_order','combined')"];
            $mt_params2 = [$station_id];

            if ($status_filter !== '') {
                // Check both validation_status (Pending/Approved/Rejected) and workflow_status (In Progress/Completed)
                $mt2_where[] = "(mt2.validation_status = ? OR mt2.workflow_status = ?)";
                $mt_params2[] = $status_filter; $mt_params2[] = $status_filter;
            }
            if ($search_filter !== '') {
                $mt2_where[] = "(mt2.customer_name LIKE ? OR mt2.job_order_service LIKE ?)";
                $s2 = '%'.$search_filter.'%';
                $mt_params2[] = $s2; $mt_params2[] = $s2;
            }

            $mt2_date = $mt_has2('transaction_date')
                ? "CASE WHEN mt2.transaction_date > '2000-01-01' THEN mt2.transaction_date ELSE mt2.created_at END"
                : "mt2.created_at";

            $part2 = "
            UNION ALL
            SELECT
                mt2.id,
                COALESCE(NULLIF(TRIM(mt2.customer_name),''),'Walk-in'),
                COALESCE(mt2.job_order_service,'Service'),
                '',
                COALESCE(mt2.workflow_status, mt2.validation_status,'Pending'),
                COALESCE(mt2.validation_status,'Pending'),
                mt2.total_amount,
                '',
                {$mt2_date},
                COALESCE(NULLIF(TRIM(mt2.customer_name),''),'Walk-in'),
                u2.name,
                'merchandise_transactions'
            FROM merchandise_transactions mt2
            LEFT JOIN users u2 ON u2.user_id = mt2.staff_id
            WHERE " . implode(' AND ', $mt2_where);
        }
    } catch (Exception $e2) { /* merchandise_transactions may not exist */ }

    $full_sql = "
        SELECT * FROM (
            {$part1}
            {$part2}
        ) combined_jo
        ORDER BY CASE WHEN status='Pending Validation' OR validation_status='Pending Validation' THEN 0 ELSE 1 END, created_at DESC
        LIMIT 100
    ";

    $r = $pdo->prepare($full_sql);
    $r->execute(array_merge($params, $mt_params2));
    $job_orders = $r->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

include __DIR__ . '/../partials/header.php';
?>
<style>
.jo-stat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px}
@media(max-width:1100px){.jo-stat-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.jo-stat-grid{grid-template-columns:repeat(2,1fr)}}
.jo-stat-card{background:#fff;border-radius:12px;padding:14px 10px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e9ecef;text-align:center;cursor:pointer;transition:transform .15s,box-shadow .15s;text-decoration:none;display:block}
.jo-stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.jo-stat-card.active-filter{border-color:#00264D;box-shadow:0 0 0 2px #00264D}
.jo-stat-card .sv{font-size:26px;font-weight:800;line-height:1.1}
.jo-stat-card .sl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-top:4px}
.jo-stat-card .si{font-size:18px;margin-bottom:6px}
.jo-card{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e9ecef}
.jo-table{width:100%;border-collapse:collapse;font-size:13px}
.jo-table th{background:#f4f5f7;font-weight:600;color:#444;padding:9px 11px;text-align:left;border-bottom:2px solid #e0e0e0;white-space:nowrap}
.jo-table td{padding:9px 11px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.jo-table tr:last-child td{border-bottom:none}
.jo-table tr:hover td{background:#fafbfc}
.jo-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-block}
.jo-act-btn{padding:5px 10px;border-radius:4px;font-size:12px;font-weight:600;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:4px;color:#fff;width:100%;justify-content:center;pointer-events:auto;user-select:none;}
.jo-act-btn:hover{opacity:.88;transform:scale(1.02);}
.jo-act-btn:active{transform:scale(0.98);}
.action-col{display:flex;flex-direction:column;gap:4px;min-width:90px}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter-bar input{padding:7px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px;width:220px}
/* Adjust modal */
.adj-modal{display:none;position:fixed;z-index:9999;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center}
.adj-modal.open{display:flex}
.adj-modal-box{background:#fff;border-radius:12px;width:90%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25)}
.adj-modal-hdr{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid #e9ecef}
.adj-modal-hdr h3{margin:0;font-size:16px;font-weight:700;color:#00264D}
.adj-modal-body{padding:22px}
.adj-modal-ftr{display:flex;justify-content:flex-end;gap:10px;padding:16px 22px;border-top:1px solid #e9ecef}
.adj-form-group{margin-bottom:14px}
.adj-form-group label{display:block;margin-bottom:5px;font-weight:600;font-size:13px;color:#333}
.adj-form-group input,.adj-form-group textarea{width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;box-sizing:border-box}
.adj-form-group textarea{resize:vertical;min-height:70px}
</style>

<div class="page-head">
  <div>
    <h1 class="h1" style="font-size:20px;font-weight:bold;color:#00264D;"><i class="fas fa-wrench" style="margin-right:8px;"></i>JOB ORDERS</h1>
    <div class="sub" style="font-size:13px;opacity:.85;color:#6c757d;font-weight:bold;">MANAGER VALIDATION &bull; APPROVE / REJECT / ADJUST</div>
  </div>
</div>

<?php if ($flash_success): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
  <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($flash_success); ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
  <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_error); ?>
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="jo-card">
  <form method="GET" action="manager_job_orders.php" class="filter-bar">
    <select name="status" style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px;min-width:180px;">
      <option value="">All Statuses</option>
      <?php foreach (['Pending Validation','Approved','Validated','In Progress','Completed','Rejected','Cancelled','Adjusted'] as $opt): ?>
      <option value="<?php echo $opt; ?>" <?php echo $status_filter === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="search" value="<?php echo htmlspecialchars($search_filter); ?>" placeholder="Search customer, service, staff..." style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;font-size:13px;width:240px;">
    <button type="submit" style="padding:7px 14px;background:#00264D;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-search"></i> Search</button>
    <?php if ($status_filter !== '' || $search_filter !== ''): ?>
    <a href="manager_job_orders.php" style="padding:7px 14px;background:#f8f9fa;color:#495057;border:1px solid #ddd;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times"></i> Clear</a>
    <?php endif; ?>
    <span style="font-size:12px;color:#6c757d;margin-left:auto;"><?php echo count($job_orders); ?> record(s)
      <?php if ($search_filter !== ''): ?> &mdash; matching "<strong><?php echo htmlspecialchars($search_filter); ?></strong>"<?php endif; ?>
    </span>
  </form>

  <div style="overflow-x:auto;">
    <table class="jo-table">
      <thead>
        <tr><th>Job ID</th><th>Customer</th><th>Service</th><th>Staff</th><th>Status</th><th>Cost</th><th>Created</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php
      $stMap = [
          'Pending Validation'=>['#FFF3CD','#92400E'],
          'Approved'          =>['#D1FAE5','#065F46'],
          'Validated'         =>['#D1FAE5','#065F46'],
          'In Progress'       =>['#DBEAFE','#1E40AF'],
          'Completed'         =>['#DCFCE7','#14532D'],
          'Rejected'          =>['#FEE2E2','#991B1B'],
          'Cancelled'         =>['#FEE2E2','#991B1B'],
          'Adjusted'          =>['#E0E7FF','#3730A3'],
      ];
      foreach ($job_orders as $j):
          $st  = $j['validation_status'] ?: $j['status'] ?: 'Pending Validation';
          $sc  = $stMap[$st] ?? ['#f3f4f6','#374151'];
          $svc = htmlspecialchars($j['service_type'] ?: $j['service_description'] ?: '—');
          $isPending   = in_array($st, ['Pending Validation','Pending']);
          $canAdjust   = !in_array($st, ['Completed','Cancelled']);
      ?>
      <tr>
        <td style="font-weight:700;color:#00264D;">#<?php echo (int)$j['id']; ?></td>
        <td><?php echo htmlspecialchars($j['cust']); ?></td>
        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $svc; ?>"><?php echo $svc; ?></td>
        <td style="color:#6c757d;"><?php echo htmlspecialchars($j['staff_name'] ?? '—'); ?></td>
        <td><span class="jo-badge" style="background:<?php echo $sc[0]; ?>;color:<?php echo $sc[1]; ?>;"><?php echo htmlspecialchars($st); ?></span></td>
        <td style="font-weight:600;">₱<?php echo number_format((float)$j['estimated_cost'],2); ?></td>
        <td style="color:#6c757d;font-size:11px;"><?php echo $j['created_at'] ? date('M j, Y g:i A',strtotime($j['created_at'])) : '—'; ?></td>
        <td>
          <div class="action-col">
            <?php if ($isPending): ?>
            <form method="POST" style="margin:0;">
              <input type="hidden" name="action" value="approve_reject_job_order">
              <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
              <input type="hidden" name="job_source" value="<?php echo htmlspecialchars($j['_source'] ?? 'job_orders'); ?>">
              <input type="hidden" name="approval_action" value="approve">
              <button type="submit" class="jo-act-btn" style="background:#28a745;" onclick="return confirm('Approve job order #<?php echo (int)$j['id']; ?>?')">
                <i class="fas fa-check"></i> Approve
              </button>
            </form>
            <form method="POST" style="margin:0;">
              <input type="hidden" name="action" value="approve_reject_job_order">
              <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
              <input type="hidden" name="job_source" value="<?php echo htmlspecialchars($j['_source'] ?? 'job_orders'); ?>">
              <input type="hidden" name="approval_action" value="reject">
              <button type="submit" class="jo-act-btn" style="background:#dc3545;" onclick="return confirm('Reject job order #<?php echo (int)$j['id']; ?>?')">
                <i class="fas fa-times"></i> Reject
              </button>
            </form>
            <?php endif; ?>
            <?php if ($canAdjust): ?>
            <button type="button" class="jo-act-btn" style="background:#00264D;color:#fff;"
              onclick="openAdjust(<?php echo (int)$j['id']; ?>, '<?php echo addslashes(htmlspecialchars($j['cust'])); ?>', <?php echo (float)$j['estimated_cost']; ?>, '<?php echo addslashes(htmlspecialchars($j['notes'] ?? '')); ?>')">
              <i class="fas fa-edit"></i> Adjust
            </button>
            <?php endif; ?>
            <?php if (!$isPending && !$canAdjust): ?>
            <span style="font-size:11px;color:#9ca3af;">—</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($job_orders)): ?>
      <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No job orders found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADJUST MODAL -->
<div class="adj-modal" id="adjModal">
  <div class="adj-modal-box">
    <div class="adj-modal-hdr">
      <h3><i class="fas fa-edit" style="margin-right:8px;color:#002F70;"></i>Adjust Job Order <span id="adjJobLabel"></span></h3>
      <button onclick="closeAdjust()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#aaa;">&times;</button>
    </div>
    <form method="POST" id="adjForm">
      <input type="hidden" name="action" value="adjust_job_order">
      <input type="hidden" name="job_id" id="adjJobId">
      <div class="adj-modal-body">
        <div class="adj-form-group">
          <label>Service Cost (₱) <span style="color:#dc3545;">*</span></label>
          <input type="number" name="adj_cost" id="adjCost" step="0.01" min="0" required>
        </div>
        <div class="adj-form-group">
          <label>Remarks / Notes</label>
          <textarea name="adj_remarks" id="adjRemarks" placeholder="Update remarks if needed..."></textarea>
        </div>
        <div class="adj-form-group">
          <label>Manager Adjustment Notes <span style="color:#dc3545;">*</span></label>
          <textarea name="adj_mgr_notes" id="adjMgrNotes" placeholder="Explain why this adjustment was made (required for audit trail)..." required></textarea>
        </div>
      </div>
      <div class="adj-modal-ftr">
        <button type="button" onclick="closeAdjust()" style="padding:8px 18px;background:#f8f9fa;color:#495057;border:1px solid #ddd;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="padding:8px 18px;background:#002F70;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;"><i class="fas fa-save"></i> Save Adjustment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdjust(id, cust, cost, notes) {
    try {
        console.log('Opening adjust modal for job ID:', id);
        document.getElementById('adjJobId').value    = id;
        document.getElementById('adjJobLabel').textContent = '#' + id + ' — ' + cust;
        document.getElementById('adjCost').value     = parseFloat(cost).toFixed(2);
        document.getElementById('adjRemarks').value  = notes || '';
        document.getElementById('adjMgrNotes').value = '';
        document.getElementById('adjModal').classList.add('open');
        console.log('Adjust modal opened successfully');
    } catch (error) {
        console.error('Error opening adjust modal:', error);
        alert('Error opening adjust modal: ' + error.message);
    }
}
function closeAdjust() {
    try {
        document.getElementById('adjModal').classList.remove('open');
        console.log('Adjust modal closed');
    } catch (error) {
        console.error('Error closing adjust modal:', error);
    }
}
window.addEventListener('click', function(e) {
    if (e.target.id === 'adjModal') closeAdjust();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAdjust();
});

// Additional debugging
document.addEventListener('DOMContentLoaded', function() {
    console.log('Manager Job Orders page loaded');
    const adjustButtons = document.querySelectorAll('.jo-act-btn[style*="background:#00264D"]');
    console.log('Found adjust buttons:', adjustButtons.length);
    
    adjustButtons.forEach((button, index) => {
        console.log(`Adjust button ${index + 1}:`, button);
        button.addEventListener('click', function(e) {
            console.log('Adjust button clicked:', e.target);
        });
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
