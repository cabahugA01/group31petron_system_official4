<?php
/**
 * Admin Variance Reports - Merchandise (Admin Functional Form)
 * Access: admin and superadmin roles only.
 */
$page_id = 'admin_variance_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

$msg_success = $_SESSION['success'] ?? '';
$msg_error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── Handle Form Submissions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // 1. Manually add/flag a new variance report
    if ($action === 'create') {
        $txn_id    = trim($_POST['transaction_id'] ?? '');
        $item_code = trim($_POST['item_code'] ?? '');
        $item_name = trim($_POST['item_name'] ?? '');
        $expected  = (float)($_POST['expected_quantity'] ?? 0);
        $actual    = (float)($_POST['actual_quantity'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');
        $encoder   = trim($_POST['encoder_name'] ?? '');
        $manager   = trim($_POST['manager_name'] ?? '');
        $status    = trim($_POST['status'] ?? 'flagged');
        $target_station = ($role === 'superadmin') ? (int)($_POST['station_id'] ?? $station_id) : $station_id;

        if (!in_array($status, ['flagged', 'cleared', 'pending_review'])) {
            $status = 'flagged';
        }

        if (empty($txn_id) || empty($item_name) || empty($reason)) {
            $_SESSION['error'] = 'Transaction ID, Item Name, and Reason are required.';
        } else {
            // Calculate variance
            $variance = $actual - $expected;

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO variance_reports 
                    (transaction_id, item_code, item_name, expected_quantity, actual_quantity, variance, reason, encoder_name, manager_name, status, station_id, flagged_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([
                    $txn_id, $item_code, $item_name, $expected, $actual, $variance, $reason, $encoder, $manager, $status, $target_station
                ]);

                log_activity($pdo, $me['id'], 'Create Merchandise Variance', "Created merchandise variance report for txn #{$txn_id} (Item: {$item_name}, Status: {$status})");

                $_SESSION['success'] = 'Merchandise variance report created successfully.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Database error: ' . $e->getMessage();
            }
        }
        header('Location: admin_variance_reports.php'); exit;
    }

    // 2. Update status & details of an existing variance report
    if ($action === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $status  = trim($_POST['status'] ?? '');
        $reason  = trim($_POST['reason'] ?? '');
        $manager = trim($_POST['manager_name'] ?? '');
        $actual  = isset($_POST['actual_quantity']) ? (float)$_POST['actual_quantity'] : null;

        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid report ID.';
        } elseif (!in_array($status, ['flagged', 'cleared', 'pending_review'])) {
            $_SESSION['error'] = 'Invalid status selected.';
        } else {
            try {
                // Fetch existing record first to verify access & compute variance if quantity changed
                $stmt = $pdo->prepare("SELECT * FROM variance_reports WHERE id = ? " . ($role !== 'superadmin' ? "AND station_id = {$station_id}" : ""));
                $stmt->execute([$id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $_SESSION['error'] = 'Report not found or access denied.';
                } else {
                    $update_fields = ["status = ?", "reason = ?", "manager_name = ?", "updated_at = NOW()"];
                    $params = [$status, $reason, $manager];

                    if ($actual !== null) {
                        $expected = (float)$existing['expected_quantity'];
                        $variance = $actual - $expected;
                        $update_fields[] = "actual_quantity = ?";
                        $update_fields[] = "variance = ?";
                        $params[] = $actual;
                        $params[] = $variance;
                    }

                    if ($status === 'cleared') {
                        $update_fields[] = "resolved_at = NOW()";
                    }

                    $params[] = $id;

                    $up_stmt = $pdo->prepare("UPDATE variance_reports SET " . implode(', ', $update_fields) . " WHERE id = ?");
                    $up_stmt->execute($params);

                    log_activity($pdo, $me['id'], 'Update Merchandise Variance', "Updated merchandise variance report #{$id} status to {$status}");

                    $_SESSION['success'] = 'Variance report updated successfully.';
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Database error: ' . $e->getMessage();
            }
        }
        header('Location: admin_variance_reports.php'); exit;
    }

    // 3. Delete a variance report
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid report ID.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM variance_reports WHERE id = ? " . ($role !== 'superadmin' ? "AND station_id = {$station_id}" : ""));
                $stmt->execute([$id]);

                if ($stmt->rowCount() > 0) {
                    log_activity($pdo, $me['id'], 'Delete Merchandise Variance', "Deleted merchandise variance report #{$id}");
                    $_SESSION['success'] = 'Variance report deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Report not found or access denied.';
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Database error: ' . $e->getMessage();
            }
        }
        header('Location: admin_variance_reports.php'); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from     = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to       = trim($_GET['date_to']   ?? date('Y-m-d'));
$filter_status = trim($_GET['status']    ?? '');
$search_q      = trim($_GET['q']         ?? '');
$export        = trim($_GET['export']    ?? '');

$filter_station = ($role === 'superadmin') ? (int)($_GET['station'] ?? 0) : $station_id;

// ── Station Name ──────────────────────────────────────────────────────────────
$station_name = 'All Stations';
if ($filter_station > 0) {
    try {
        $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $sn->execute([$filter_station]);
        $station_name = $sn->fetchColumn() ?: 'Station';
    } catch (Exception $e) {}
}

// ── Build Query WHERE ─────────────────────────────────────────────────────────
$where  = ["DATE(flagged_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_station > 0) {
    $where[] = "station_id = ?";
    $params[] = $filter_station;
}
if ($filter_status !== '') {
    $where[] = "status = ?";
    $params[] = $filter_status;
}
if ($search_q !== '') {
    $where[] = "(transaction_id LIKE ? OR item_code LIKE ? OR item_name LIKE ? OR reason LIKE ? OR encoder_name LIKE ? OR manager_name LIKE ?)";
    $s_wild = '%' . $search_q . '%';
    array_push($params, $s_wild, $s_wild, $s_wild, $s_wild, $s_wild, $s_wild);
}
$where_sql = implode(' AND ', $where);

// ── Fetch Summary Counts ──────────────────────────────────────────────────────
$cnt_total = $cnt_flagged = $cnt_cleared = $cnt_pending = 0;
$sum_variance = 0;
try {
    $s = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='flagged' THEN 1 ELSE 0 END) as flagged_c,
            SUM(CASE WHEN status='cleared' THEN 1 ELSE 0 END) as cleared_c,
            SUM(CASE WHEN status='pending_review' THEN 1 ELSE 0 END) as pending_c,
            SUM(ABS(variance)) as total_var
        FROM variance_reports 
        WHERE {$where_sql}
    ");
    $s->execute($params);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $cnt_total    = (int)($row['total']     ?? 0);
    $cnt_flagged  = (int)($row['flagged_c']   ?? 0);
    $cnt_cleared  = (int)($row['cleared_c']  ?? 0);
    $cnt_pending  = (int)($row['pending_c']  ?? 0);
    $sum_variance = (float)($row['total_var'] ?? 0);
} catch (Exception $e) {}

// ── Fetch Variance Records ────────────────────────────────────────────────────
$records = [];
try {
    $stmt = $pdo->prepare("
        SELECT vr.*, s.name as station_name
        FROM variance_reports vr
        LEFT JOIN stations s ON vr.station_id = s.id
        WHERE {$where_sql}
        ORDER BY FIELD(vr.status, 'flagged', 'pending_review', 'cleared'), vr.flagged_at DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Stations list for superadmin ──────────────────────────────────────────────
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── EXPORT ────────────────────────────────────────────────────────────────────
if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="merchandise_variance_reports_'.date('Ymd').'.xls"');
    echo '<html><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff}</style></head><body>';
    echo '<h2>Merchandise Variance Reports Oversight</h2><p>Period: '.$date_from.' – '.$date_to.' | Station: '.$station_name.'</p>';
    echo '<table><thead><tr><th>ID</th><th>Flagged Date</th><th>Station</th><th>Transaction ID</th><th>Item Code</th><th>Item Name</th><th>Expected Qty</th><th>Actual Qty</th><th>Variance</th><th>Reason</th><th>Encoder</th><th>Manager</th><th>Status</th></tr></thead><tbody>';
    foreach ($records as $r) {
        $var_sign = $r['variance'] > 0 ? '+' : '';
        echo '<tr><td>MVAR-'.$r['id'].'</td><td>'.date('M d, Y',strtotime($r['flagged_at'])).'</td>';
        echo '<td>'.htmlspecialchars($r['station_name']??'').'</td><td>'.htmlspecialchars($r['transaction_id']).'</td>';
        echo '<td>'.htmlspecialchars($r['item_code']).'</td><td>'.htmlspecialchars($r['item_name']).'</td>';
        echo '<td>'.number_format($r['expected_quantity'],2).'</td><td>'.number_format($r['actual_quantity'],2).'</td>';
        echo '<td>'.$var_sign.number_format($r['variance'],2).'</td><td>'.htmlspecialchars($r['reason']).'</td>';
        echo '<td>'.htmlspecialchars($r['encoder_name'] ?? '—').'</td><td>'.htmlspecialchars($r['manager_name'] ?? '—').'</td>';
        echo '<td>'.htmlspecialchars(strtoupper($r['status'])).'</td></tr>';
    }
    echo '</tbody></table></body></html>'; exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
html,body{max-width:100vw;overflow-x:hidden}
.mvr-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.mvr-head h1{margin:0 0 4px;font-size:22px;font-weight:700;color:#00264D;display:flex;align-items:center;gap:9px}
.mvr-subtitle{font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px}
.mvr-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .13s;height:36px;white-space:nowrap}
.mvr-btn-primary{background:#002F6C;color:#fff}.mvr-btn-primary:hover{background:#001f4d;color:#fff}
.mvr-btn-excel{background:#1d6f42;color:#fff}.mvr-btn-excel:hover{background:#155a34;color:#fff}
.mvr-btn-back{background:#6c757d;color:#fff}.mvr-btn-back:hover{background:#545b62;color:#fff}
.mvr-btn-filter{background:#002F6C;color:#fff}.mvr-btn-filter:hover{background:#001f4d;color:#fff}
.mvr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}
.mvr-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;padding:16px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.mvr-card.c-blue{border-left:4px solid #1e40af}.mvr-card.c-red{border-left:4px solid #dc2626}
.mvr-card.c-amber{border-left:4px solid #d97706}.mvr-card.c-green{border-left:4px solid #16a34a}
.mvr-card-ico{width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;color:#002F6C}
.mvr-card-meta h3{margin:0;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.mvr-card-meta h2{margin:2px 0 0;font-size:24px;font-weight:900;color:#00264D;line-height:1}
.mvr-card-meta span{font-size:11px;color:#94a3b8}
.mvr-filter{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:16px}
.mvr-fg{display:flex;flex-direction:column;gap:3px}
.mvr-fg label{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.mvr-fg input,.mvr-fg select{padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;height:34px;box-sizing:border-box}
.mvr-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.mvr-table-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px}
.mvr-table-title{font-size:13px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:.3px;margin:0}
.mvr-tbl{width:100%;border-collapse:collapse;font-size:12px}
.mvr-tbl thead tr{background:#002F6C}
.mvr-tbl thead th{padding:10px 12px;text-align:left;font-size:10px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.mvr-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.mvr-tbl tbody tr:hover{background:#eff6ff}
.mvr-tbl tbody td{padding:10px 12px;color:#334155;vertical-align:middle;word-break:break-all}
.mvr-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase}
.badge-flagged{background:#fee2e2;color:#991b1b}
.badge-pending_review{background:#fef3c7;color:#92400e}
.badge-cleared{background:#dcfce7;color:#166534}
.mvr-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.mvr-empty i{font-size:44px;display:block;margin-bottom:14px;opacity:.4}
.var-high{color:#dc2626;font-weight:700}
.var-ok{color:#16a34a;font-weight:700}

/* Modal Styling */
.mvr-modal{display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.5);align-items:center;justify-content:center;z-index:9999;padding:20px;box-sizing:border-box}
.mvr-modal-box{background:#fff;border-radius:12px;width:100%;max-width:550px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);overflow:hidden;animation:mvrFadeIn 0.15s ease-out}
@keyframes mvrFadeIn { from{opacity:0;transform:scale(0.95)} to{opacity:1;transform:scale(1)} }
.mvr-modal-head{padding:14px 18px;background:#002F6C;color:#fff;display:flex;align-items:center;justify-content:space-between}
.mvr-modal-head h3{margin:0;font-size:15px;font-weight:700}
.mvr-modal-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1}
.mvr-modal-body{padding:20px;max-height:80vh;overflow-y:auto}
.mvr-modal-foot{padding:12px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-group{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
.form-group.full{grid-column:1 / -1}
.form-group label{font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.3px}
.form-control{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;box-sizing:border-box}
.form-control:focus{outline:none;border-color:#002F6C}
.mvr-btn-action{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;border:none;background:#f1f5f9;color:#334155;transition:background 0.1s}
.mvr-btn-action:hover{background:#e2e8f0}
</style>

<div class="mvr-head">
    <div>
        <h1><i class="fas fa-chart-line"></i> Merchandise Variance Reports</h1>
        <div class="mvr-subtitle">Track, audit, and log merchandise quantity discrepancies, price errors, and delivery issues.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button onclick="openCreateModal()" class="mvr-btn mvr-btn-primary"><i class="fas fa-plus"></i> New Report</button>
        <a href="?<?= http_build_query(['date_from'=>$date_from,'date_to'=>$date_to,'station'=>$filter_station,'status'=>$filter_status,'q'=>$search_q,'export'=>'excel']) ?>" class="mvr-btn mvr-btn-excel"><i class="fas fa-file-excel"></i> Export Excel</a>
        <a href="admin_dashboard.php" class="mvr-btn mvr-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php if ($msg_success): ?>
<div style="padding:12px 16px;background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;border-radius:8px;margin-bottom:16px;font-weight:600;"><i class="fas fa-check-circle" style="margin-right:6px;"></i><?= htmlspecialchars($msg_success) ?></div>
<?php endif; ?>
<?php if ($msg_error): ?>
<div style="padding:12px 16px;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;margin-bottom:16px;font-weight:600;"><i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?= htmlspecialchars($msg_error) ?></div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="mvr-cards">
    <div class="mvr-card c-blue">
        <div class="mvr-card-ico"><i class="fas fa-list-ul"></i></div>
        <div class="mvr-card-meta">
            <h3>Total Reports</h3>
            <h2><?= number_format($cnt_total) ?></h2>
            <span>All status categories</span>
        </div>
    </div>
    <div class="mvr-card c-red">
        <div class="mvr-card-ico"><i class="fas fa-flag"></i></div>
        <div class="mvr-card-meta">
            <h3>Flagged</h3>
            <h2><?= number_format($cnt_flagged) ?></h2>
            <span>Discrepancies flagged</span>
        </div>
    </div>
    <div class="mvr-card c-amber">
        <div class="mvr-card-ico"><i class="fas fa-history"></i></div>
        <div class="mvr-card-meta">
            <h3>Pending Review</h3>
            <h2><?= number_format($cnt_pending) ?></h2>
            <span>Manager investigation</span>
        </div>
    </div>
    <div class="mvr-card c-green">
        <div class="mvr-card-ico"><i class="fas fa-check-circle"></i></div>
        <div class="mvr-card-meta">
            <h3>Cleared</h3>
            <h2><?= number_format($cnt_cleared) ?></h2>
            <span>Total Var: <?= number_format($sum_variance, 2) ?> units</span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form method="get" class="mvr-filter">
    <?php if ($role === 'superadmin' && !empty($stations)): ?>
    <div class="mvr-fg">
        <label>Station</label>
        <select name="station">
            <option value="0" <?= $filter_station==0?'selected':'' ?>>All Stations</option>
            <?php foreach ($stations as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filter_station==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="mvr-fg">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    <div class="mvr-fg">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    <div class="mvr-fg">
        <label>Status</label>
        <select name="status">
            <option value="" <?= $filter_status===''?'selected':'' ?>>All Status</option>
            <option value="flagged" <?= $filter_status==='flagged'?'selected':'' ?>>Flagged</option>
            <option value="pending_review" <?= $filter_status==='pending_review'?'selected':'' ?>>Pending Review</option>
            <option value="cleared" <?= $filter_status==='cleared'?'selected':'' ?>>Cleared</option>
        </select>
    </div>
    <div class="mvr-fg" style="flex-grow:1;min-width:180px;">
        <label>Search Keyword</label>
        <input type="text" name="q" placeholder="Txn ID, item, encoder, manager..." value="<?= htmlspecialchars($search_q) ?>">
    </div>
    <button type="submit" class="mvr-btn mvr-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_variance_reports.php" class="mvr-btn mvr-btn-back"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table Card -->
<div class="mvr-table-card">
    <div class="mvr-table-hd">
        <h3 class="mvr-table-title"><i class="fas fa-database"></i> Merchandise Discrepancy Records</h3>
        <span style="font-size:11px;color:#64748b;"><?= number_format(count($records)) ?> records matching filters</span>
    </div>

    <?php if (empty($records)): ?>
    <div class="mvr-empty">
        <i class="fas fa-check-double" style="color:#10b981;"></i>
        <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">Zero Discrepancies Found</div>
        <div style="font-size:13px;">No merchandise variances match your active filter search.</div>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;max-width:100%;">
        <table class="mvr-tbl" id="mvrTable">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Expected Quantity</th>
                    <th>Actual Quantity</th>
                    <th>Variance</th>
                    <th>Reason</th>
                    <th>Encoder</th>
                    <th>Manager</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r):
                    $var_val  = (float)$r['variance'];
                    $var_sign = $var_val > 0 ? '+' : '';
                    $var_class = $var_val < 0 ? 'var-high' : 'var-ok';
                    $status_label = str_replace('_', ' ', $r['status']);
                ?>
                <tr id="row-<?= $r['id'] ?>">
                    <td style="font-weight:600;color:#002F6C;"><?= htmlspecialchars($r['transaction_id']) ?></td>
                    <td style="font-family:monospace;"><?= htmlspecialchars($r['item_code'] ?: 'N/A') ?></td>
                    <td style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($r['item_name']) ?></td>
                    <td><?= number_format($r['expected_quantity'], 2) ?></td>
                    <td><?= number_format($r['actual_quantity'], 2) ?></td>
                    <td class="<?= $var_class ?>" style="font-family:monospace;">
                        <?= $var_sign . number_format($var_val, 2) ?>
                    </td>
                    <td title="<?= htmlspecialchars($r['reason']) ?>">
                        <?= htmlspecialchars(substr($r['reason'], 0, 30)) ?><?= strlen($r['reason']) > 30 ? '…' : '' ?>
                    </td>
                    <td><?= htmlspecialchars($r['encoder_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($r['manager_name'] ?: '—') ?></td>
                    <td><span class="mvr-badge badge-<?= $r['status'] ?>"><?= htmlspecialchars($status_label) ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button onclick='openViewModal(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="mvr-btn-action" title="View Details">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick='openEditModal(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="mvr-btn-action" style="color:#0284c7;" title="Edit Report">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button onclick="confirmDelete(<?= $r['id'] ?>, '<?= htmlspecialchars($r['transaction_id'], ENT_QUOTES) ?>')" class="mvr-btn-action" style="color:#dc2626;" title="Delete Report">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
            <select id="rowsPerPage" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
                <option value="10">10</option><option value="25" selected>25</option>
                <option value="50">50</option><option value="100">100</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="pageInfo" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
            <div style="display:flex;gap:4px;">
                <button id="prevPage" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                <button id="nextPage" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;">Next <i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- VIEW MODAL -->
<div id="viewModal" class="mvr-modal" onclick="if(event.target===this)closeMvrModal('viewModal')">
    <div class="mvr-modal-box">
        <div class="mvr-modal-head">
            <h3><i class="fas fa-info-circle"></i> Merchandise Variance Details</h3>
            <button class="mvr-modal-close" onclick="closeMvrModal('viewModal')">&times;</button>
        </div>
        <div class="mvr-modal-body">
            <table style="width:100%;border-collapse:collapse;font-size:13px;line-height:1.8;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="width:40%;font-weight:600;color:#64748b;">Transaction ID:</td><td id="v_transaction_id" style="font-weight:700;color:#002F6C;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Item Code:</td><td id="v_item_code" style="font-family:monospace;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Item Name:</td><td id="v_item_name" style="font-weight:600;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Expected Qty:</td><td id="v_expected_quantity"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Actual Qty:</td><td id="v_actual_quantity"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Variance:</td><td id="v_variance" style="font-weight:700;font-family:monospace;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Encoder (Staff):</td><td id="v_encoder_name"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Manager:</td><td id="v_manager_name"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Status:</td><td><span id="v_status_badge" class="mvr-badge"></span></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Flagged At:</td><td id="v_flagged_at"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="font-weight:600;color:#64748b;">Resolved At:</td><td id="v_resolved_at"></td></tr>
                <tr><td colspan="2" style="font-weight:600;color:#64748b;padding-top:10px;">Manager's Notes / Reason:</td></tr>
                <tr><td colspan="2" id="v_reason" style="background:#f8fafc;padding:12px;border-radius:8px;border-left:4px solid #002F6C;font-style:italic;margin-top:4px;"></td></tr>
            </table>
        </div>
        <div class="mvr-modal-foot">
            <button onclick="closeMvrModal('viewModal')" class="mvr-btn mvr-btn-back">Close</button>
        </div>
    </div>
</div>

<!-- CREATE MODAL -->
<div id="createModal" class="mvr-modal" onclick="if(event.target===this)closeMvrModal('createModal')">
    <div class="mvr-modal-box">
        <div class="mvr-modal-head" style="background:#002F6C;">
            <h3><i class="fas fa-plus-circle"></i> Create Merchandise Variance Report</h3>
            <button class="mvr-modal-close" onclick="closeMvrModal('createModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="mvr-modal-body">
                <?php if ($role === 'superadmin'): ?>
                <div class="form-group">
                    <label>Station <span style="color:#ef4444;">*</span></label>
                    <select name="station_id" class="form-control" required>
                        <?php foreach ($stations as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] === $station_id ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Transaction ID <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="transaction_id" class="form-control" placeholder="e.g. MERCH2026125328218" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Item Code</label>
                        <input type="text" name="item_code" class="form-control" placeholder="e.g. ITEM-0821">
                    </div>
                    <div class="form-group">
                        <label>Item Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="item_name" class="form-control" placeholder="e.g. Petron Ultron Racing 1L" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expected Quantity <span style="color:#ef4444;">*</span></label>
                        <input type="number" step="0.01" name="expected_quantity" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Actual Quantity <span style="color:#ef4444;">*</span></label>
                        <input type="number" step="0.01" name="actual_quantity" class="form-control" placeholder="0.00" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Encoder (Staff)</label>
                        <input type="text" name="encoder_name" class="form-control" placeholder="e.g. Judy Lastimosa">
                    </div>
                    <div class="form-group">
                        <label>Manager</label>
                        <input type="text" name="manager_name" class="form-control" placeholder="e.g. Edgar Manager">
                    </div>
                </div>
                <div class="form-group">
                    <label>Status <span style="color:#ef4444;">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="flagged">Flagged</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="cleared">Cleared</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Discrepancy Notes / Reason <span style="color:#ef4444;">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="e.g. stock mismatch, delivery discrepancy, wrong price..." required></textarea>
                </div>
            </div>
            <div class="mvr-modal-foot">
                <button type="submit" class="mvr-btn mvr-btn-primary">Save Report</button>
                <button type="button" onclick="closeMvrModal('createModal')" class="mvr-btn mvr-btn-back">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="mvr-modal" onclick="if(event.target===this)closeMvrModal('editModal')">
    <div class="mvr-modal-box">
        <div class="mvr-modal-head" style="background:#0284c7;">
            <h3><i class="fas fa-edit"></i> Edit Merchandise Variance Report</h3>
            <button class="mvr-modal-close" onclick="closeMvrModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="e_id" name="id">
            <div class="mvr-modal-body">
                <div class="form-group">
                    <label>Transaction ID</label>
                    <input type="text" id="e_transaction_id" class="form-control" disabled style="background:#f1f5f9;">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Expected Quantity</label>
                        <input type="text" id="e_expected_quantity" class="form-control" disabled style="background:#f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label>Actual Quantity</label>
                        <input type="number" step="0.01" id="e_actual_quantity" name="actual_quantity" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Encoder (Staff)</label>
                        <input type="text" id="e_encoder_name" class="form-control" disabled style="background:#f1f5f9;">
                    </div>
                    <div class="form-group">
                        <label>Manager / Verifier <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="e_manager_name" name="manager_name" class="form-control" required placeholder="Name of Manager">
                    </div>
                </div>
                <div class="form-group">
                    <label>Status <span style="color:#ef4444;">*</span></label>
                    <select id="e_status" name="status" class="form-control" required>
                        <option value="flagged">Flagged</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="cleared">Cleared</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Adjustment Notes / Reason <span style="color:#ef4444;">*</span></label>
                    <textarea id="e_reason" name="reason" class="form-control" rows="3" required></textarea>
                </div>
            </div>
            <div class="mvr-modal-foot">
                <button type="submit" class="mvr-btn" style="background:#0284c7;color:#fff;">Save Changes</button>
                <button type="button" onclick="closeMvrModal('editModal')" class="mvr-btn mvr-btn-back">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE CONFIRMATION FORM -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="d_id">
</form>

<script>
// ── Modal Actions ─────────────────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('createModal').style.display = 'flex';
}

function closeMvrModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openViewModal(data) {
    document.getElementById('v_transaction_id').textContent = data.transaction_id || 'N/A';
    document.getElementById('v_item_code').textContent = data.item_code || 'N/A';
    document.getElementById('v_item_name').textContent = data.item_name || 'N/A';
    document.getElementById('v_expected_quantity').textContent = parseFloat(data.expected_quantity).toFixed(2);
    document.getElementById('v_actual_quantity').textContent = parseFloat(data.actual_quantity).toFixed(2);

    const variance = parseFloat(data.variance);
    const varEl = document.getElementById('v_variance');
    varEl.textContent = (variance > 0 ? '+' : '') + variance.toFixed(2);
    if (variance < 0) {
        varEl.style.color = '#dc2626';
    } else {
        varEl.style.color = '#16a34a';
    }

    document.getElementById('v_encoder_name').textContent = data.encoder_name || '—';
    document.getElementById('v_manager_name').textContent = data.manager_name || '—';
    document.getElementById('v_reason').textContent = data.reason || 'No details provided.';
    document.getElementById('v_flagged_at').textContent = data.flagged_at ? new Date(data.flagged_at).toLocaleString() : 'N/A';
    document.getElementById('v_resolved_at').textContent = data.resolved_at ? new Date(data.resolved_at).toLocaleString() : '—';

    // Status Badge Styling
    const statusBadge = document.getElementById('v_status_badge');
    statusBadge.className = 'mvr-badge badge-' + data.status;
    statusBadge.textContent = data.status.replace('_', ' ');

    document.getElementById('viewModal').style.display = 'flex';
}

function openEditModal(data) {
    document.getElementById('e_id').value = data.id;
    document.getElementById('e_transaction_id').value = data.transaction_id || '';
    document.getElementById('e_expected_quantity').value = parseFloat(data.expected_quantity).toFixed(2);
    document.getElementById('e_actual_quantity').value = parseFloat(data.actual_quantity).toFixed(2);
    document.getElementById('e_encoder_name').value = data.encoder_name || '—';
    document.getElementById('e_manager_name').value = data.manager_name || '';
    document.getElementById('e_status').value = data.status;
    document.getElementById('e_reason').value = data.reason || '';

    document.getElementById('editModal').style.display = 'flex';
}

function confirmDelete(id, txn_id) {
    if (confirm('Are you sure you want to delete the variance report for Transaction ID: ' + txn_id + '?')) {
        document.getElementById('d_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// ── Client-side Pagination & Row Controls ─────────────────────────────────────
(function() {
    const tbody = document.querySelector('#mvrTable tbody');
    if (!tbody) return;
    const allRows = Array.from(tbody.querySelectorAll('tr'));
    let page = 1, rpp = 25;
    const rppSel = document.getElementById('rowsPerPage');
    const info   = document.getElementById('pageInfo');
    const prev   = document.getElementById('prevPage');
    const next   = document.getElementById('nextPage');

    function render() {
        const total = Math.ceil(allRows.length / rpp) || 1;
        if (page > total) page = total;

        allRows.forEach(r => r.style.display = 'none');
        allRows.slice((page-1)*rpp, page*rpp).forEach(r => r.style.display = '');

        info.textContent = `Page ${page} of ${total}`;
        prev.disabled = page === 1;
        next.disabled = page >= total;
        prev.style.opacity = prev.disabled ? '0.5' : '1';
        next.style.opacity = next.disabled ? '0.5' : '1';
    }

    rppSel.addEventListener('change', () => { rpp = parseInt(rppSel.value); page = 1; render(); });
    prev.addEventListener('click', () => { if(page>1){page--;render();} });
    next.addEventListener('click', () => { if(page < Math.ceil(allRows.length/rpp)){page++;render();} });
    render();
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
