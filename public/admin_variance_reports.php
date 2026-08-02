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

function mvr_user_name_expr(string $alias): string
{
    return "COALESCE(NULLIF(TRIM({$alias}.name), ''), NULLIF(CONCAT(TRIM({$alias}.first_name), ' ', TRIM({$alias}.last_name)), ' '), {$alias}.username, 'Unassigned')";
}

function mvr_nullable_id(string $key): ?int
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }

    $id = (int)$_POST[$key];
    return $id > 0 ? $id : null;
}

function mvr_user_allowed(PDO $pdo, ?int $user_id, int $target_station, string $role): bool
{
    if ($user_id === null) {
        return true;
    }

    $sql = "SELECT COUNT(*) FROM users WHERE id = ? AND status = 'Active'";
    $params = [$user_id];

    if ($role !== 'superadmin') {
        $sql .= " AND (station_id = ? OR station_id IS NULL)";
        $params[] = $target_station;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

// Handle Form Submissions
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
        $encoder_id = mvr_nullable_id('encoder_id');
        $manager_id = mvr_nullable_id('manager_id') ?: (int)($me['id'] ?? 0);
        $status    = trim($_POST['status'] ?? 'flagged');
        $target_station = ($role === 'superadmin') ? (int)($_POST['station_id'] ?? $station_id) : $station_id;

        if (!in_array($status, ['flagged', 'cleared', 'pending_review'])) {
            $status = 'flagged';
        }

        if ($target_station <= 0) {
            $_SESSION['error'] = 'Station is required.';
        } elseif (empty($txn_id) || empty($item_name) || empty($reason)) {
            $_SESSION['error'] = 'Transaction ID, Item Name, and Reason are required.';
        } elseif (!mvr_user_allowed($pdo, $encoder_id, $target_station, $role) || !mvr_user_allowed($pdo, $manager_id, $target_station, $role)) {
            $_SESSION['error'] = 'Selected encoder or manager is not valid for this station.';
        } else {
            // Calculate variance
            $variance = $actual - $expected;

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO variance_reports 
                    (transaction_id, item_code, item_name, expected_quantity, actual_quantity, variance, reason, encoder_id, manager_id, status, station_id, flagged_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([
                    $txn_id, $item_code, $item_name, $expected, $actual, $variance, $reason, $encoder_id, $manager_id, $status, $target_station
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
        $manager_id = mvr_nullable_id('manager_id') ?: (int)($me['id'] ?? 0);
        $actual  = isset($_POST['actual_quantity']) ? (float)$_POST['actual_quantity'] : null;

        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid report ID.';
        } elseif (!in_array($status, ['flagged', 'cleared', 'pending_review'])) {
            $_SESSION['error'] = 'Invalid status selected.';
        } elseif ($manager_id <= 0) {
            $_SESSION['error'] = 'Manager is required.';
        } else {
            try {
                // Fetch existing record first to verify access & compute variance if quantity changed
                $fetch_sql = "SELECT * FROM variance_reports WHERE id = ?";
                $fetch_params = [$id];
                if ($role !== 'superadmin') {
                    $fetch_sql .= " AND station_id = ?";
                    $fetch_params[] = $station_id;
                }

                $stmt = $pdo->prepare($fetch_sql);
                $stmt->execute($fetch_params);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $_SESSION['error'] = 'Report not found or access denied.';
                } elseif (!mvr_user_allowed($pdo, $manager_id, (int)$existing['station_id'], $role)) {
                    $_SESSION['error'] = 'Selected manager is not valid for this station.';
                } else {
                    $update_fields = ["status = ?", "reason = ?", "manager_id = ?", "updated_at = NOW()"];
                    $params = [$status, $reason, $manager_id];

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

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from     = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to       = trim($_GET['date_to']   ?? date('Y-m-d'));
$filter_status = trim($_GET['status']    ?? '');
$search_q      = trim($_GET['q']         ?? '');
$export        = trim($_GET['export']    ?? '');

$filter_station = ($role === 'superadmin') ? (int)($_GET['station'] ?? 0) : $station_id;

// Station Name
$station_name = 'All Stations';
if ($filter_station > 0) {
    try {
        $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $sn->execute([$filter_station]);
        $station_name = $sn->fetchColumn() ?: 'Station';
    } catch (Exception $e) {}
}

$encoder_name_expr = mvr_user_name_expr('enc');
$manager_name_expr = mvr_user_name_expr('mgr');

// Build Query WHERE
$where  = ["DATE(vr.flagged_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_station > 0) {
    $where[] = "vr.station_id = ?";
    $params[] = $filter_station;
}
if ($filter_status !== '') {
    $where[] = "vr.status = ?";
    $params[] = $filter_status;
}
if ($search_q !== '') {
    $where[] = "(vr.transaction_id LIKE ? OR vr.item_code LIKE ? OR vr.item_name LIKE ? OR vr.reason LIKE ? OR {$encoder_name_expr} LIKE ? OR {$manager_name_expr} LIKE ?)";
    $s_wild = '%' . $search_q . '%';
    array_push($params, $s_wild, $s_wild, $s_wild, $s_wild, $s_wild, $s_wild);
}
$where_sql = implode(' AND ', $where);

// Fetch Summary Counts
$cnt_total = $cnt_flagged = $cnt_cleared = $cnt_pending = 0;
$sum_variance = 0;
try {
    $s = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN vr.status='flagged' THEN 1 ELSE 0 END) as flagged_c,
            SUM(CASE WHEN vr.status='cleared' THEN 1 ELSE 0 END) as cleared_c,
            SUM(CASE WHEN vr.status='pending_review' THEN 1 ELSE 0 END) as pending_c,
            SUM(ABS(vr.variance)) as total_var
        FROM variance_reports vr
        LEFT JOIN users enc ON vr.encoder_id = enc.id
        LEFT JOIN users mgr ON vr.manager_id = mgr.id
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

// Fetch Variance Records
$records = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            vr.id,
            vr.transaction_id,
            vr.item_code,
            vr.item_name,
            vr.expected_quantity,
            vr.actual_quantity,
            vr.variance,
            vr.reason,
            vr.encoder_id,
            {$encoder_name_expr} AS encoder_name,
            vr.manager_id,
            {$manager_name_expr} AS manager_name,
            vr.status,
            vr.station_id,
            vr.flagged_at,
            vr.resolved_at,
            vr.created_at,
            vr.updated_at,
            s.name as station_name
        FROM variance_reports vr
        LEFT JOIN stations s ON vr.station_id = s.id
        LEFT JOIN users enc ON vr.encoder_id = enc.id
        LEFT JOIN users mgr ON vr.manager_id = mgr.id
        WHERE {$where_sql}
        ORDER BY FIELD(vr.status, 'flagged', 'pending_review', 'cleared'), vr.flagged_at DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stations list for superadmin
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE LOWER(COALESCE(status, 'active')) = 'active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// User list for normalized encoder/manager selection
$user_options = [];
try {
    $user_label_expr = mvr_user_name_expr('u');
    $user_sql = "
        SELECT u.id, {$user_label_expr} AS display_name, u.role, u.station_id, s.name AS station_name
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        WHERE u.status = 'Active'
    ";
    $user_params = [];

    if ($role !== 'superadmin') {
        $user_sql .= " AND (u.station_id = ? OR u.station_id IS NULL)";
        $user_params[] = $station_id;
    }

    $user_sql .= " ORDER BY display_name";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute($user_params);
    $user_options = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// EXPORT
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
/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: 0 !important; padding-top: 16px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
.int-head h1{font-size:22px!important;font-weight:700!important;color:var(--petron-blue,#00264D)!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.int-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none!important}
/* == Outline Buttons - SuperAdmin standard == */
.mvr-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:0 16px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .15s;white-space:nowrap;background:white !important}
.mvr-btn-primary{color:#00264D !important;border-color:#00264D !important}.mvr-btn-primary:hover{background:#00264D !important;color:#fff !important}
.mvr-btn-excel{color:#00264D!important;border-color:#cbd5e1!important;background:#ffffff!important}.mvr-btn-excel:hover{background:#f8fafc!important;border-color:#00264D!important;color:#00264D!important}
.mvr-btn-back{color:#4b5563 !important;border-color:#6b7280 !important}.mvr-btn-back:hover{background:#6b7280 !important;color:#fff !important}
.mvr-btn-filter{color:#00264D !important;border-color:#00264D !important}.mvr-btn-filter:hover{background:#00264D !important;color:#fff !important}
.mvr-btn-danger{color:#dc2626 !important;border-color:#dc2626 !important}.mvr-btn-danger:hover{background:#dc2626 !important;color:#fff !important}
/* == KPI Cards == */
.mvr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}
.mvr-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;padding:16px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.mvr-card-ico{width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;color:#002F6C}
.mvr-card-meta h3{margin:0;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.mvr-card-meta h2{margin:2px 0 0;font-size:24px;font-weight:900;color:#00264D;line-height:1}
.mvr-card-meta span{font-size:11px;color:#94a3b8}
/* == Filter Bar == */
.mvr-filter{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:16px}
.mvr-fg{display:flex;flex-direction:column;gap:3px}
.mvr-fg label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.mvr-fg input,.mvr-fg select{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box}
.mvr-fg input:focus,.mvr-fg select:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1)}
/* == Table Card - matches SuperAdmin ato-table == */
.mvr-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.mvr-table-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px}
.mvr-table-title{font-size:13px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:.3px;margin:0}
.mvr-tbl{width:100%;border-collapse:collapse;font-size:11px}
.mvr-tbl thead tr{background:#002F70}
.mvr-tbl thead th{padding:9px 10px;text-align:left;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #001a3d;vertical-align:middle}
.mvr-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.mvr-tbl tbody tr:hover td{background:#eff6ff}
.mvr-tbl tbody td{padding:9px 10px;color:#334155;vertical-align:middle;word-break:break-all;background:#fff;font-size:11px}
.mvr-badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase}
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
.mvr-modal-head{padding:14px 18px;background:#002F70;color:#fff;display:flex;align-items:center;justify-content:space-between}
.mvr-modal-head h3{margin:0;font-size:15px;font-weight:700}
.mvr-modal-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1}
.mvr-modal-body{padding:20px;max-height:80vh;overflow-y:auto}
.mvr-modal-foot{padding:12px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-group{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
.form-group.full{grid-column:1 / -1}
.form-group label{font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.3px}
.form-control{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;box-sizing:border-box}
.form-control:focus{outline:none;border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1)}
.mvr-btn-action{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;border:none;background:#f1f5f9;color:#334155;transition:background 0.1s}
.mvr-btn-action:hover{background:#e2e8f0}
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-chart-line"></i> Merchandise Variance Reports</h1>
        <div class="sub">Track, audit, and log merchandise quantity discrepancies, price errors, and delivery issues.</div>
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
    <div class="mvr-card">
        <div class="mvr-card-ico"><i class="fas fa-list-ul"></i></div>
        <div class="mvr-card-meta">
            <h3>Total Reports</h3>
            <h2><?= number_format($cnt_total) ?></h2>
            <span>All status categories</span>
        </div>
    </div>
    <div class="mvr-card">
        <div class="mvr-card-ico"><i class="fas fa-flag"></i></div>
        <div class="mvr-card-meta">
            <h3>Flagged</h3>
            <h2><?= number_format($cnt_flagged) ?></h2>
            <span>Discrepancies flagged</span>
        </div>
    </div>
    <div class="mvr-card">
        <div class="mvr-card-ico"><i class="fas fa-history"></i></div>
        <div class="mvr-card-meta">
            <h3>Pending Review</h3>
            <h2><?= number_format($cnt_pending) ?></h2>
            <span>Manager investigation</span>
        </div>
    </div>
    <div class="mvr-card">
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
    <div class="mvr-fg" style="flex-grow:1;">
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
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;">
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
                        <?= htmlspecialchars(substr($r['reason'], 0, 30)) ?><?= strlen($r['reason']) > 30 ? '—¦' : '' ?>
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

    <?php endif; ?>
</div>


<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     MODALS
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->

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
                        <select name="encoder_id" class="form-control">
                            <option value="">Unassigned</option>
                            <?php foreach ($user_options as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['display_name'] . ' - ' . ucfirst($u['role']) . (($role === 'superadmin' && !empty($u['station_name'])) ? ' - ' . $u['station_name'] : '')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Manager</label>
                        <select name="manager_id" class="form-control">
                            <option value="<?= (int)($me['id'] ?? 0) ?>">Current User</option>
                            <?php foreach ($user_options as $u): ?>
                                <?php if ((int)$u['id'] === (int)($me['id'] ?? 0)) { continue; } ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['display_name'] . ' - ' . ucfirst($u['role']) . (($role === 'superadmin' && !empty($u['station_name'])) ? ' - ' . $u['station_name'] : '')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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
                        <select id="e_manager_id" name="manager_id" class="form-control" required>
                            <option value="">Select manager</option>
                            <?php foreach ($user_options as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['display_name'] . ' - ' . ucfirst($u['role']) . (($role === 'superadmin' && !empty($u['station_name'])) ? ' - ' . $u['station_name'] : '')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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
                <button type="submit" class="mvr-btn mvr-btn-primary">Save Changes</button>
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
// â”€â”€ Modal Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    document.getElementById('e_manager_id').value = data.manager_id || '';
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

</script>


<?php require_once __DIR__ . '/../partials/footer.php'; ?>
