<?php
/**
 * ADMIN VARIANCE REPORTS — JO & Merchandise Anomalies (Station-Scoped)
 * Sources from: variance_alerts WHERE transaction_type != 'Fuel'
 * Fuel variance is a separate module (admin_anomaly_monitoring.php).
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

try {
    log_activity($pdo, $me['id'], 'View Variance Reports',
        "Admin {$me['name']} accessed JO/Merchandise variance reports for station #{$station_id}");
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
$start         = $_GET['start']  ?? date('Y-m-d', strtotime('-90 days'));
$end           = $_GET['end']    ?? date('Y-m-d');
$filter_status = trim($_GET['status'] ?? '');
$filter_type   = trim($_GET['type']   ?? '');
$search        = trim($_GET['search'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d', strtotime('-90 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = date('Y-m-d');

// ── Export ────────────────────────────────────────────────────────────────────
$export = trim($_GET['export'] ?? '');
if (in_array($export, ['excel','csv','pdf'])) {
    $ew  = ["va.station_id=?","va.transaction_type!='Fuel'","DATE(va.created_at) BETWEEN ? AND ?","va.item_identifier NOT LIKE '[ARCHIVED]%'"];
    $ep  = [$station_id, $start, $end];
    if ($filter_status !== '') { $ew[] = 'va.status=?';           $ep[] = $filter_status; }
    if ($filter_type   !== '') { $ew[] = 'va.transaction_type=?'; $ep[] = $filter_type;   }
    if ($search        !== '') { $ew[] = '(va.item_identifier LIKE ? OR va.investigation_notes LIKE ?)'; $ep[] = "%$search%"; $ep[] = "%$search%"; }
    $erows = [];
    try {
        $s = $pdo->prepare("SELECT va.id,va.transaction_type,va.item_identifier,va.variance_amount,va.status,va.investigation_notes,va.created_at,va.updated_at,u.name AS staff_name FROM variance_alerts va LEFT JOIN users u ON va.user_id=u.id WHERE ".implode(' AND ',$ew)." ORDER BY va.created_at DESC");
        $s->execute($ep); $erows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="variance_alerts_'.date('Ymd_His').'.csv"');
        $out = fopen('php://output','w');
        fputs($out,"\xEF\xBB\xBF");
        fputcsv($out,['Alert ID','Type','Reference / Item','Variance Amount','Status','Staff','Notes','Flagged At']);
        foreach ($erows as $r) fputcsv($out,['#'.$r['id'],$r['transaction_type'],$r['item_identifier'],$r['variance_amount'],ucfirst($r['status']),$r['staff_name']??'—',$r['investigation_notes']??'',$r['created_at']]);
        fclose($out); exit;
    }
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="variance_alerts_'.date('Ymd_His').'.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px}th{background:#002F6C;color:#fff;font-size:11px;text-transform:uppercase}</style></head><body>';
        echo '<h2>JO & Merchandise Variance Alerts</h2><p>Period: '.$start.' to '.$end.' | Records: '.count($erows).'</p>';
        echo '<table><thead><tr><th>Alert ID</th><th>Type</th><th>Reference / Item</th><th>Variance Amount</th><th>Status</th><th>Staff</th><th>Notes</th><th>Flagged At</th></tr></thead><tbody>';
        foreach ($erows as $r) {
            echo '<tr><td>#'.htmlspecialchars($r['id']).'</td><td>'.htmlspecialchars($r['transaction_type']).'</td><td>'.htmlspecialchars($r['item_identifier']).'</td>';
            echo '<td style="text-align:right">'.number_format((float)$r['variance_amount'],2).'</td><td>'.htmlspecialchars(ucfirst($r['status'])).'</td>';
            echo '<td>'.htmlspecialchars($r['staff_name']??'—').'</td><td>'.htmlspecialchars($r['investigation_notes']??'').'</td><td>'.htmlspecialchars($r['created_at']).'</td></tr>';
        }
        echo '</tbody></table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach ($erows as $r) {
            $sc = ['open'=>'#dc2626','investigating'=>'#d97706','escalated'=>'#7c3aed','resolved'=>'#16a34a'][$r['status']] ?? '#374151';
            $tbody .= '<tr><td>#'.htmlspecialchars($r['id']).'</td><td>'.htmlspecialchars($r['transaction_type']).'</td><td>'.htmlspecialchars($r['item_identifier']).'</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;color:#dc2626">₱'.number_format((float)$r['variance_amount'],2).'</td>';
            $tbody .= '<td style="color:'.$sc.';font-weight:600">'.htmlspecialchars(ucfirst($r['status'])).'</td>';
            $tbody .= '<td>'.htmlspecialchars($r['staff_name']??'—').'</td>';
            $tbody .= '<td>'.date('M d, Y H:i',strtotime($r['created_at'])).'</td></tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Variance Alerts</title><style>body{font-family:Arial,sans-serif;font-size:11px;padding:20px}.hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px}h1{color:#002F6C;font-size:18px;margin:0 0 4px}table{width:100%;border-collapse:collapse}th{background:#002F6C;color:#fff;padding:6px 8px;font-size:9px;text-transform:uppercase;text-align:left}td{padding:5px 8px;border-bottom:1px solid #e2e8f0}tr:nth-child(even) td{background:#f8fafc}.pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}</style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer">Print / Save PDF</button></div>';
        echo '<div class="hdr"><h1>JO & Merchandise Variance Alerts</h1><p>Period: '.htmlspecialchars($start).' &mdash; '.htmlspecialchars($end).' | Records: '.count($erows).'</p></div>';
        echo '<table><thead><tr><th>Alert ID</th><th>Type</th><th>Reference / Item</th><th>Variance</th><th>Status</th><th>Staff</th><th>Flagged At</th></tr></thead><tbody>';
        echo ($tbody ?: '<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px">No records found.</td></tr>');
        echo '</tbody></table></body></html>'; exit;
    }
}

// ── Summary counts (all-time, not date-filtered, so cards always show real totals) ─
$counts = ['open'=>0,'investigating'=>0,'escalated'=>0,'resolved'=>0,'total'=>0];
try {
    $cs = $pdo->prepare("SELECT status,COUNT(*) AS n FROM variance_alerts WHERE station_id=? AND transaction_type!='Fuel' AND item_identifier NOT LIKE '[ARCHIVED]%' GROUP BY status");
    $cs->execute([$station_id]);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) { $counts[$row['status']] = (int)$row['n']; $counts['total'] += (int)$row['n']; }
} catch (Exception $e) {}

// ── Fetch alerts ──────────────────────────────────────────────────────────────
$alerts = [];
try {
    $where  = ["va.station_id=?","va.transaction_type!='Fuel'","DATE(va.created_at) BETWEEN ? AND ?","va.item_identifier NOT LIKE '[ARCHIVED]%'"];
    $params = [$station_id,$start,$end];
    if ($filter_status !== '') { $where[] = 'va.status=?';           $params[] = $filter_status; }
    if ($filter_type   !== '') { $where[] = 'va.transaction_type=?'; $params[] = $filter_type;   }
    if ($search        !== '') { $where[] = '(va.item_identifier LIKE ? OR va.investigation_notes LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
    $stmt = $pdo->prepare("SELECT va.id,va.transaction_type,va.item_identifier,va.variance_amount,va.status,va.investigation_notes,va.created_at,va.updated_at,u.name AS staff_name FROM variance_alerts va LEFT JOIN users u ON va.user_id=u.id WHERE ".implode(' AND ',$where)." ORDER BY CASE WHEN va.status IN ('open','escalated') THEN 0 ELSE 1 END, va.created_at DESC LIMIT 500");
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<!-- Page Header -->
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h1 class="h1" style="margin:0 0 4px 0;"><i class="fas fa-exclamation-triangle"></i> Variance Reports</h1>
        <div class="sub">Compliance review of JO &amp; Merchandise anomalies flagged by the system for this station</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'excel'])); ?>" style="background:#1d6f42;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'csv'])); ?>" style="background:#003d7a;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'pdf'])); ?>" style="background:#dc2626;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        <a href="admin_dashboard.php" style="background:#6c757d;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px;">
    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;border-left:4px solid #dc2626;">
        <div style="width:42px;height:42px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-exclamation-circle" style="color:#dc2626;font-size:18px;"></i></div>
        <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Open</div><div style="font-size:22px;font-weight:700;color:#dc2626;"><?php echo $counts['open']; ?></div></div>
    </div>
    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;border-left:4px solid #d97706;">
        <div style="width:42px;height:42px;border-radius:10px;background:#fffbeb;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-search" style="color:#d97706;font-size:18px;"></i></div>
        <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Investigating</div><div style="font-size:22px;font-weight:700;color:#d97706;"><?php echo $counts['investigating']; ?></div></div>
    </div>
    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;border-left:4px solid #7c3aed;">
        <div style="width:42px;height:42px;border-radius:10px;background:#f5f3ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-arrow-up" style="color:#7c3aed;font-size:18px;"></i></div>
        <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Escalated</div><div style="font-size:22px;font-weight:700;color:#7c3aed;"><?php echo $counts['escalated']; ?></div></div>
    </div>
    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;border-left:4px solid #16a34a;">
        <div style="width:42px;height:42px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-check-circle" style="color:#16a34a;font-size:18px;"></i></div>
        <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Resolved</div><div style="font-size:22px;font-weight:700;color:#16a34a;"><?php echo $counts['resolved']; ?></div></div>
    </div>
    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-list" style="color:#475569;font-size:18px;"></i></div>
        <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Total</div><div style="font-size:22px;font-weight:700;color:#1e293b;"><?php echo $counts['total']; ?></div></div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="padding:16px 18px;margin-bottom:18px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;">
            <label style="display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;">Date Range</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <span style="color:#6b7280;font-size:13px;">to</span>
                <input type="date" name="end"   value="<?php echo htmlspecialchars($end); ?>"   style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
            </div>
        </div>
        <div style="">
            <label style="display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;">Type</label>
            <select name="type" style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Types</option>
                <option value="Merchandise"   <?php echo $filter_type==='Merchandise'   ?'selected':''; ?>>Merchandise</option>
                <option value="Job Order"     <?php echo $filter_type==='Job Order'     ?'selected':''; ?>>Job Order</option>
                <option value="JO+Merchandise"<?php echo $filter_type==='JO+Merchandise'?'selected':''; ?>>JO + Merchandise</option>
            </select>
        </div>
        <div style="">
            <label style="display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;">Status</label>
            <select name="status" style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                <option value="">All Statuses</option>
                <option value="open"          <?php echo $filter_status==='open'          ?'selected':''; ?>>Open</option>
                <option value="investigating" <?php echo $filter_status==='investigating' ?'selected':''; ?>>Investigating</option>
                <option value="escalated"     <?php echo $filter_status==='escalated'     ?'selected':''; ?>>Escalated</option>
                <option value="resolved"      <?php echo $filter_status==='resolved'      ?'selected':''; ?>>Resolved</option>
            </select>
        </div>
        <div style="">
            <label style="display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Reference or notes..." style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
        </div>
        <div style="display:flex;gap:8px;align-self:flex-end;">
            <button type="submit" style="background:#002F6C;color:#fff;padding:8px 16px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-filter"></i> Apply</button>
            <a href="admin_variance_reports.php" style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;"><i class="fas fa-times"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Alerts Table -->
<div class="card" style="padding:0;overflow-x:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:#002F6C;">
                <th style="padding:11px 14px;text-align:left;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;">#</th>
                <th style="padding:11px 14px;text-align:left;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Type</th>
                <th style="padding:11px 14px;text-align:left;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Reference / Item</th>
                <th style="padding:11px 14px;text-align:right;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;">Variance (₱)</th>
                <th style="padding:11px 14px;text-align:center;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Status</th>
                <th style="padding:11px 14px;text-align:left;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Staff</th>
                <th style="padding:11px 14px;text-align:left;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;">Notes</th>
                <th style="padding:11px 14px;text-align:center;font-weight:600;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;">Flagged At</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($alerts)): ?>
            <tr>
                <td colspan="8" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                    <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    <div style="font-size:15px;font-weight:600;color:#64748b;margin-bottom:4px;">No variance alerts found</div>
                    <div style="font-size:13px;">No JO or Merchandise anomalies match the selected filters.</div>
                    <a href="admin_variance_reports.php" style="display:inline-block;margin-top:12px;background:#002F6C;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">Reset Filters</a>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($alerts as $a):
                $st = strtolower($a['status'] ?? 'open');
                $sc = ['open'=>['#dc2626','#fee2e2'],'investigating'=>['#d97706','#fef3c7'],'escalated'=>['#7c3aed','#f5f3ff'],'resolved'=>['#16a34a','#dcfce7']][$st] ?? ['#374151','#f1f5f9'];
                $ty = $a['transaction_type'] ?? '';
                $ty_color = $ty==='Job Order'?'#7c3aed':($ty==='Merchandise'?'#0891b2':'#374151');
                $ty_bg    = $ty==='Job Order'?'#f5f3ff':($ty==='Merchandise'?'#e0f2fe':'#f1f5f9');
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:11px 14px;font-family:monospace;font-size:12px;color:#64748b;">#<?php echo $a['id']; ?></td>
                <td style="padding:11px 14px;">
                    <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;color:<?php echo $ty_color; ?>;background:<?php echo $ty_bg; ?>;">
                        <?php echo htmlspecialchars($ty); ?>
                    </span>
                </td>
                <td style="padding:11px 14px;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($a['item_identifier']??''); ?>">
                    <?php echo htmlspecialchars($a['item_identifier'] ?? '—'); ?>
                </td>
                <td style="padding:11px 14px;text-align:right;font-family:monospace;font-weight:700;color:#dc2626;">
                    ₱<?php echo number_format((float)($a['variance_amount']??0), 2); ?>
                </td>
                <td style="padding:11px 14px;text-align:center;">
                    <span style="display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;color:<?php echo $sc[0]; ?>;background:<?php echo $sc[1]; ?>;">
                        <?php echo ucfirst(htmlspecialchars($a['status'])); ?>
                    </span>
                </td>
                <td style="padding:11px 14px;font-size:12px;"><?php echo htmlspecialchars($a['staff_name'] ?? '—'); ?></td>
                <td style="padding:11px 14px;font-size:12px;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#64748b;" title="<?php echo htmlspecialchars($a['investigation_notes']??''); ?>">
                    <?php echo htmlspecialchars(substr($a['investigation_notes'] ?? '', 0, 50)); ?>
                    <?php if (strlen($a['investigation_notes']??'') > 50): ?>…<?php endif; ?>
                </td>
                <td style="padding:11px 14px;text-align:center;white-space:nowrap;font-size:12px;color:#64748b;">
                    <?php echo date('M d, Y', strtotime($a['created_at'])); ?><br>
                    <span style="font-size:11px;"><?php echo date('H:i', strtotime($a['created_at'])); ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
