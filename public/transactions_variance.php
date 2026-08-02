<?php
/**
 * Variance Alerts — Full Anomaly-Handling Workflow
 * Merchandise & Job Orders only. Fuel has its own reconciliation flow.
 */
$page_id = 'mgr_txn_variance';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager','admin','superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php'); exit;
}

// â”€â”€ POST HANDLER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = trim($_POST['action'] ?? '');
    $variance_id = (int)($_POST['variance_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');

    $qs_parts = [];
    foreach (['status','type','date','q','show_archived'] as $k) {
        if (!empty($_POST['_' . $k])) $qs_parts[$k] = $_POST['_' . $k];
    }
    $redirect_qs = $qs_parts ? '?' . http_build_query($qs_parts) : '';

    // Archive a resolved alert
    if ($action === 'archive_variance' && $variance_id) {
        try {
            $stmt = $pdo->prepare(
                "UPDATE variance_alerts SET status='resolved', investigation_notes=CONCAT(COALESCE(investigation_notes,''), '\n[ARCHIVED by {$me['name']} on ".date('Y-m-d H:i').".]'), updated_at=NOW()
                 WHERE id=? AND station_id=? AND status='resolved'"
            );
            $stmt->execute([$variance_id, $station_id]);
            if ($stmt->rowCount()) {
                // Mark archived via a prefix in notes — no schema change needed
                $pdo->prepare("UPDATE variance_alerts SET item_identifier=CONCAT('[ARCHIVED] ', item_identifier) WHERE id=? AND station_id=? AND item_identifier NOT LIKE '[ARCHIVED]%'")->execute([$variance_id, $station_id]);
                log_activity($pdo, $me['id'], 'Variance_Archived', "Alert #$variance_id archived by {$me['name']}");
                $_SESSION['success'] = 'Alert archived and removed from main view.';
            }
        } catch (Exception $e) { $_SESSION['error'] = 'Archive failed: ' . $e->getMessage(); }
        header('Location: transactions_variance.php' . $redirect_qs); exit;
    }

    // Re-open a resolved/escalated alert
    if ($action === 'reopen_variance' && $variance_id) {
        try {
            $stmt = $pdo->prepare("UPDATE variance_alerts SET status='open', updated_at=NOW() WHERE id=? AND station_id=?");
            $stmt->execute([$variance_id, $station_id]);
            if ($stmt->rowCount()) {
                log_activity($pdo, $me['id'], 'Variance_Reopened', "Alert #$variance_id re-opened by {$me['name']}");
                $_SESSION['success'] = 'Alert re-opened as Open.';
            }
        } catch (Exception $e) { $_SESSION['error'] = 'Re-open failed: ' . $e->getMessage(); }
        header('Location: transactions_variance.php' . $redirect_qs); exit;
    }

    // Status transitions: investigate / resolve / escalate / update
    $valid_actions = ['update_variance','resolve_variance','escalate_variance','investigate_variance'];
    if (in_array($action, $valid_actions) && $variance_id) {
        $status_map = [
            'update_variance'      => $_POST['new_status'] ?? 'open',
            'resolve_variance'     => 'resolved',
            'escalate_variance'    => 'escalated',
            'investigate_variance' => 'investigating',
        ];
        $new_status = $status_map[$action];
        if (!in_array($new_status, ['open','investigating','resolved','escalated'])) $new_status = 'open';
        try {
            $stmt = $pdo->prepare(
                "UPDATE variance_alerts SET status=?, investigation_notes=?, updated_at=NOW()
                 WHERE id=? AND station_id=?"
            );
            $stmt->execute([$new_status, $notes, $variance_id, $station_id]);
            if ($stmt->rowCount() === 0) {
                $_SESSION['error'] = 'Alert not found or no permission.';
            } else {
                log_activity($pdo, $me['id'], 'Variance_' . ucfirst($new_status),
                    "Alert #$variance_id â†’ $new_status by {$me['name']}");
                $labels = [
                    'investigating' => 'Marked as Investigating.',
                    'resolved'      => 'Alert resolved successfully.',
                    'escalated'     => 'Alert escalated to Admin/Audit.',
                    'open'          => 'Alert re-opened.',
                ];
                $_SESSION['success'] = $labels[$new_status] ?? 'Variance alert updated.';
            }
        } catch (Exception $e) { $_SESSION['error'] = 'Database error: ' . $e->getMessage(); }
        header('Location: transactions_variance.php' . $redirect_qs); exit;
    }
}

// â”€â”€ FILTERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$filter_status   = trim($_GET['status']        ?? '');
$filter_type     = trim($_GET['type']          ?? '');
$filter_date     = trim($_GET['date']          ?? '');
$search_q        = trim($_GET['q']             ?? '');
$show_archived   = isset($_GET['show_archived']);
if (!in_array($filter_status, ['open','investigating','resolved','escalated'])) $filter_status = '';

// â”€â”€ EXPORT WORKFLOW â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel', 'pdf'])) {
    try {
        $exp_where  = ["va.station_id = ?", "va.transaction_type != 'Fuel'"];
        $exp_params = [$station_id];
        if (!$show_archived) $exp_where[] = "va.item_identifier NOT LIKE '[ARCHIVED]%'";
        if ($filter_status !== '') { $exp_where[] = 'va.status = ?';           $exp_params[] = $filter_status; }
        if ($filter_type   !== '') { $exp_where[] = 'va.transaction_type = ?'; $exp_params[] = $filter_type;   }
        if ($filter_date   !== '') { $exp_where[] = 'DATE(va.created_at) = ?'; $exp_params[] = $filter_date;   }
        if ($search_q      !== '') {
            $exp_where[]  = '(va.item_identifier LIKE ? OR va.investigation_notes LIKE ?)';
            $exp_params[] = '%' . $search_q . '%';
            $exp_params[] = '%' . $search_q . '%';
        }
        $stmt = $pdo->prepare(
            "SELECT va.id, va.transaction_type, va.item_identifier, va.variance_amount,
                    va.status, va.investigation_notes, va.created_at, va.updated_at,
                    COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name
             FROM variance_alerts va LEFT JOIN users u ON va.user_id = u.id
             WHERE " . implode(' AND ', $exp_where) . " ORDER BY va.created_at DESC"
         );
        $stmt->execute($exp_params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $export_fmt = $_GET['export'];

        if ($export_fmt === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="variance_alerts_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Alert ID','Type','Product/SKU/Service','Variance Amount','Status','Staff','Notes','Flagged At','Last Updated']);
            foreach ($rows as $r) {
                fputcsv($out, ['#'.$r['id'],$r['transaction_type'],$r['item_identifier'],
                    $r['variance_amount'],ucfirst($r['status']),$r['staff_name']??'—',
                    $r['investigation_notes']??'',$r['created_at'],$r['updated_at']]);
            }
            fclose($out); exit;
        } elseif ($export_fmt === 'excel') {
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="variance_alerts_' . date('Ymd_His') . '.xls');
            echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
            echo '<head><meta charset="UTF-8"><style>table { border-collapse: collapse; } td, th { border: 1px solid #ddd; padding: 8px; } th { background-color: #002F70; color: white; font-weight: bold; }</style></head>';
            echo '<body>';
            echo '<table>';
            echo '<thead><tr>';
            echo '<th>Alert ID</th><th>Type</th><th>Product/SKU/Service</th><th>Variance Amount</th><th>Status</th><th>Staff</th><th>Notes</th><th>Flagged At</th><th>Last Updated</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>#' . htmlspecialchars($r['id']) . '</td>';
                echo '<td>' . htmlspecialchars($r['transaction_type']) . '</td>';
                echo '<td>' . htmlspecialchars($r['item_identifier']) . '</td>';
                echo '<td style="text-align:right;">' . number_format((float)$r['variance_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars(ucfirst($r['status'])) . '</td>';
                echo '<td>' . htmlspecialchars($r['staff_name'] ?? '—') . '</td>';
                echo '<td>' . htmlspecialchars($r['investigation_notes'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($r['created_at']) . '</td>';
                echo '<td>' . htmlspecialchars($r['updated_at']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</body></html>';
            exit;
        } elseif ($export_fmt === 'pdf') {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html>';
            echo '<html><head>';
            echo '<meta charset="UTF-8">';
            echo '<title>Variance Alerts - ' . date('Y-m-d') . '</title>';
            echo '<style>';
            echo 'body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }';
            echo 'h1 { color: #002F70; font-size: 18px; margin-bottom: 10px; }';
            echo 'table { width: 100%; border-collapse: collapse; margin-top: 10px; }';
            echo 'th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }';
            echo 'th { background-color: #002F70; color: white; font-weight: bold; font-size: 11px; }';
            echo 'td { font-size: 10px; }';
            echo '.amount { text-align: right; font-weight: bold; color: #dc3545; }';
            echo '@media print { button { display: none; } }';
            echo '</style>';
            echo '</head><body>';
            echo '<h1>Variance Alerts Report</h1>';
            echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Total Records: ' . count($rows) . '</p>';
            echo '<button onclick="window.print()" style="padding:10px 20px;background:#002F70;color:white;border:none;border-radius:6px;cursor:pointer;margin-bottom:10px;">Print / Save as PDF</button>';
            echo '<table>';
            echo '<thead><tr>';
            echo '<th>Alert ID</th><th>Type</th><th>Product/SKU/Service</th><th>Variance</th><th>Status</th><th>Staff</th><th>Notes</th><th>Flagged At</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>#' . htmlspecialchars($r['id']) . '</td>';
                echo '<td>' . htmlspecialchars($r['transaction_type']) . '</td>';
                echo '<td>' . htmlspecialchars($r['item_identifier']) . '</td>';
                echo '<td class="amount">₱' . number_format((float)$r['variance_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars(ucfirst($r['status'])) . '</td>';
                echo '<td>' . htmlspecialchars($r['staff_name'] ?? '—') . '</td>';
                echo '<td>' . htmlspecialchars($r['investigation_notes'] ?? '') . '</td>';
                echo '<td>' . date('M d, Y H:i', strtotime($r['created_at'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</body></html>';
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Export failed: ' . $e->getMessage();
        header('Location: transactions_variance.php'); exit;
    }
}

// â”€â”€ SUMMARY COUNTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$counts = ['open'=>0,'investigating'=>0,'escalated'=>0,'resolved'=>0,'total'=>0];
try {
    $cs = $pdo->prepare("SELECT status, COUNT(*) AS n FROM variance_alerts
        WHERE station_id=? AND transaction_type!='Fuel' AND item_identifier NOT LIKE '[ARCHIVED]%'
        GROUP BY status");
    $cs->execute([$station_id]);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['status']] = (int)$row['n'];
        $counts['total'] += (int)$row['n'];
    }
} catch (Exception $e) {}

// â”€â”€ FETCH ALERTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$variance_alerts = [];
try {
    $where  = ["va.station_id = ?", "va.transaction_type != 'Fuel'"];
    $params = [$station_id];
    if (!$show_archived) $where[] = "va.item_identifier NOT LIKE '[ARCHIVED]%'";
    if ($filter_status !== '') { $where[] = 'va.status = ?';           $params[] = $filter_status; }
    if ($filter_type   !== '') { $where[] = 'va.transaction_type = ?'; $params[] = $filter_type;   }
    if ($filter_date   !== '') { $where[] = 'DATE(va.created_at) = ?'; $params[] = $filter_date;   }
    if ($search_q      !== '') {
        $where[]  = '(va.item_identifier LIKE ? OR va.investigation_notes LIKE ?)';
        $params[] = '%' . $search_q . '%';
        $params[] = '%' . $search_q . '%';
    }
    $stmt = $pdo->prepare("
        SELECT va.id, va.transaction_type, va.item_identifier, va.variance_amount,
               va.status, va.investigation_notes, va.created_at, va.updated_at,
               va.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name, u.id AS staff_uid
        FROM variance_alerts va LEFT JOIN users u ON va.user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY CASE WHEN va.status IN ('open','escalated') THEN 0 ELSE 1 END, va.created_at DESC
    ");
    $stmt->execute($params);
    $variance_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $variance_alerts = []; }

// â”€â”€ LIVE ANOMALY DETECTION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$detected_anomalies = [];
try {
    $stmt = $pdo->prepare("
        SELECT mt.id, mt.transaction_id, mt.item_sku, mt.total_amount,
               mt.quantity, mt.unit_price, mt.staff_id, mt.created_at, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name
        FROM merchandise_transactions mt LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id=? AND mt.total_amount<=0
          AND mt.transaction_type NOT IN ('fuel','Fuel')
          AND DATE(mt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND NOT EXISTS (SELECT 1 FROM variance_alerts va
              WHERE va.station_id=mt.station_id
                AND va.item_identifier LIKE CONCAT('%', mt.transaction_id, '%')
                AND va.transaction_type='Merchandise')
        ORDER BY mt.created_at DESC LIMIT 20");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $detected_anomalies[] = ['type'=>'Merchandise','subtype'=>'Wrong Total',
            'ref'=>$r['transaction_id']??'TXN-'.$r['id'],'item'=>$r['item_sku']??'Unknown Item',
            'variance'=>(float)$r['total_amount'],'staff'=>$r['staff_name']??'—',
            'staff_id'=>$r['staff_id'],'date'=>$r['created_at'],
            'description'=>'Transaction total is zero or negative.'];
    }
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT mt.id, mt.transaction_id, mt.item_sku, mt.total_amount,
               mt.quantity, mt.unit_price, mt.staff_id, mt.created_at, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name
        FROM merchandise_transactions mt LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id=? AND mt.unit_price=0 AND mt.quantity>0
          AND mt.transaction_type NOT IN ('fuel','Fuel')
          AND DATE(mt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND NOT EXISTS (SELECT 1 FROM variance_alerts va
              WHERE va.station_id=mt.station_id
                AND va.item_identifier LIKE CONCAT('%', mt.transaction_id, '%')
                AND va.transaction_type='Merchandise')
        ORDER BY mt.created_at DESC LIMIT 20");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $detected_anomalies[] = ['type'=>'Merchandise','subtype'=>'Zero Price',
            'ref'=>$r['transaction_id']??'TXN-'.$r['id'],'item'=>$r['item_sku']??'Unknown Item',
            'variance'=>0.0,'staff'=>$r['staff_name']??'—','staff_id'=>$r['staff_id'],
            'date'=>$r['created_at'],'description'=>'Item sold with unit price of ₱0.00.'];
    }
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT jo.id, jo.service_type, jo.total_cost, jo.created_at, jo.user_id,
               jo.actual_parts_cost,
               COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') as staff_name, COUNT(jop.id) AS parts_count,
               COALESCE(SUM(jop.total_cost),0) AS parts_total
        FROM job_orders jo
        LEFT JOIN job_order_parts jop ON jop.job_order_id=jo.id
        LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
        WHERE jo.station_id=? AND jo.status IN ('Completed','Verified','finalized')
          AND DATE(jo.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND NOT EXISTS (SELECT 1 FROM variance_alerts va
              WHERE va.station_id=jo.station_id
                AND va.item_identifier LIKE CONCAT('%JO-', jo.id, '%')
                AND va.transaction_type='Merchandise')
        GROUP BY jo.id HAVING parts_count>0 AND ABS(COALESCE(jo.actual_parts_cost,0)-parts_total)>1
        ORDER BY jo.created_at DESC LIMIT 20");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $diff = abs((float)($r['actual_parts_cost']??0)-(float)$r['parts_total']);
        $detected_anomalies[] = ['type'=>'Job Order','subtype'=>'Parts Mismatch',
            'ref'=>'JO-'.$r['id'],'item'=>$r['service_type']??'Job Order #'.$r['id'],
            'variance'=>$diff,'staff'=>$r['staff_name']??'—','staff_id'=>$r['user_id'],
            'date'=>$r['created_at'],'description'=>'Parts cost in JO does not match inventory deduction records.'];
    }
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>



<!-- â”€â”€ PAGE HEADER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
    <div>
        <h1 class="h1" style="margin:0 0 4px 0;">Variance Alerts</h1>
        <div class="sub">Check flagged anomalies in stock, pump readings, or service fees.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])); ?>" class="va-hdr-btn" style="background:#ffffff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:6px;color:#00264D;font-size:13px;font-weight:600;border:1px solid #cbd5e1;transition:all .15s;">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])); ?>" class="va-hdr-btn" style="background:#003d7a;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;color:#fff;font-size:13px;font-weight:600;">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'pdf'])); ?>" class="va-hdr-btn" style="background:#ffffff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:6px;color:#00264D;font-size:13px;font-weight:600;border:1px solid #cbd5e1;transition:all .15s;" target="_blank">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= in_array($role, ['admin', 'superadmin']) ? 'admin_dashboard.php' : 'manager_dashboard.php'; ?>" class="va-hdr-btn" style="background:#6c757d;text-decoration:none;display:inline-flex;align-items:center;gap:6px;height:36px;padding:8px 14px;border-radius:8px;color:#fff;font-size:13px;font-weight:600;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

</div>

<!-- â”€â”€ LIVE ANOMALY BANNER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<?php if (!empty($detected_anomalies)): ?>
<div class="va-anomaly-banner">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
        <i class="fas fa-exclamation-triangle" style="color:#dc3545;font-size:18px;"></i>
        <strong style="color:#842029;font-size:14px;"><?= count($detected_anomalies); ?> New Anomal<?= count($detected_anomalies)>1?'ies':'y'; ?> Detected</strong>
        <span style="font-size:12px;color:#6c757d;">— Not yet logged. Review and flag below.</span>
    </div>
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="va-table" style="font-size:12px;">
            <thead><tr><th>Type</th><th>Anomaly</th><th>Reference</th><th>Item / Service</th><th>Variance</th><th>Staff</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($detected_anomalies as $a): ?>
            <tr>
                <td><span class="va-type-badge type-<?= strtolower($a['type']==='Job Order'?'jo':'merch'); ?>"><?= $a['type']==='Job Order'?'JO':'MERCH'; ?></span></td>
                <td style="color:#842029;font-weight:600;"><?= htmlspecialchars($a['subtype']); ?></td>
                <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($a['ref']); ?></td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($a['item']); ?>"><?= htmlspecialchars($a['item']); ?></td>
                <td style="font-weight:700;color:#dc3545;"><?= number_format($a['variance'],2); ?></td>
                <td style="font-size:11px;"><?= htmlspecialchars($a['staff']); ?></td>
                <td style="font-size:11px;white-space:nowrap;"><?= date('M d, H:i', strtotime($a['date'])); ?></td>
                <td>
                    <button type="button" class="va-act-btn va-act-flag"
                        onclick="openFlagModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES); ?>)">
                        <i class="fas fa-flag"></i> Flag Alert
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- â”€â”€ FILTER BAR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="va-filter-bar">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div class="va-filter-group">
            <label>Status</label>
            <select name="status" class="va-select" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="open"          <?= $filter_status==='open'          ?'selected':''; ?>>Open</option>
                <option value="investigating" <?= $filter_status==='investigating' ?'selected':''; ?>>Investigating</option>
                <option value="escalated"     <?= $filter_status==='escalated'     ?'selected':''; ?>>Escalated</option>
                <option value="resolved"      <?= $filter_status==='resolved'      ?'selected':''; ?>>Resolved</option>
            </select>
        </div>
        <div class="va-filter-group">
            <label>Type</label>
            <select name="type" class="va-select" onchange="this.form.submit()">
                <option value="">All Types</option>
                <option value="Merchandise" <?= $filter_type==='Merchandise'?'selected':''; ?>>Merchandise</option>
                <option value="Job Order"   <?= $filter_type==='Job Order'  ?'selected':''; ?>>Job Order</option>
            </select>
        </div>
        <div class="va-filter-group">
            <label>Date Flagged</label>
            <input type="date" name="date" class="va-select" value="<?= htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
        </div>
        <div class="va-filter-group" style="flex:1;">
            <label>Search</label>
            <input type="text" name="q" class="va-select" placeholder="SKU / item / notes—¦" value="<?= htmlspecialchars($search_q); ?>">
        </div>
        <div class="va-filter-group" style="justify-content:flex-end;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
                <input type="checkbox" name="show_archived" value="1" <?= $show_archived?'checked':''; ?> onchange="this.form.submit()">
                Show Archived
            </label>
        </div>
        <button type="submit" class="va-btn va-btn-primary"><i class="fas fa-search"></i> Search</button>
        <a href="transactions_variance.php" class="va-btn va-btn-secondary"><i class="fas fa-times"></i> Clear</a>
    </form>
</div>

<!-- â”€â”€ RESULTS COUNT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div style="margin-bottom:10px;font-size:13px;color:#666;display:flex;align-items:center;gap:12px;">
    <span>Showing <strong><?= count($variance_alerts); ?></strong> alert<?= count($variance_alerts)!==1?'s':''; ?>
    <?php if ($filter_status||$filter_type||$filter_date||$search_q): ?>
        <span style="color:#0d6efd;">(filtered)</span>
    <?php endif; ?></span>
    <?php if ($filter_status||$filter_type||$filter_date||$search_q||$show_archived): ?>
    <a href="transactions_variance.php" style="font-size:12px;color:#dc3545;text-decoration:none;"><i class="fas fa-times-circle"></i> Clear filters</a>
    <?php endif; ?>
</div>

<!-- â”€â”€ VARIANCE ALERTS TABLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="po-table-wrap">
    <table class="po-table">
            <thead>
                <tr>
                    <th>Alert ID</th><th>Type</th><th>Product / SKU / Service</th>
                    <th>Variance Amt</th><th>Staff</th><th>Date Flagged</th>
                    <th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($variance_alerts)): ?>
            <tr>
                <td colspan="8">
                    <div class="va-empty-state">
                        <div class="va-empty-icon"><i class="fas fa-shield-alt"></i></div>
                        <div class="va-empty-title">No Variance Alerts Found</div>
                        <div class="va-empty-sub">All entries are within normal parameters. No discrepancies detected<?= ($filter_status||$filter_type||$filter_date||$search_q)?' for the current filters.':'.'; ?></div>
                        <?php if ($filter_status||$filter_type||$filter_date||$search_q): ?>
                        <a href="transactions_variance.php" class="va-btn va-btn-secondary" style="margin-top:12px;"><i class="fas fa-times"></i> Clear Filters</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($variance_alerts as $v):
                $status   = strtolower($v['status'] ?? 'open');
                $vid      = (int)$v['id'];
                $varAmt   = (float)$v['variance_amount'];
                $txnType  = $v['transaction_type'] ?? 'Merchandise';
                $isArch   = str_starts_with($v['item_identifier'] ?? '', '[ARCHIVED]');
                $dispItem = $isArch ? substr($v['item_identifier'], 11) : ($v['item_identifier'] ?? '—');

                $statusMeta = [
                    'open'          => ['label'=>'Open'],
                    'investigating' => ['label'=>'Investigating'],
                    'resolved'      => ['label'=>'Resolved'],
                    'escalated'     => ['label'=>'Escalated'],
                ];
                $sm = $statusMeta[$status] ?? ['label'=>ucfirst($status)];

                $jVid     = htmlspecialchars(json_encode($vid),                                              ENT_QUOTES);
                $jType    = htmlspecialchars(json_encode($txnType),                                          ENT_QUOTES);
                $jItem    = htmlspecialchars(json_encode($dispItem),                                         ENT_QUOTES);
                $jVar     = htmlspecialchars(json_encode(number_format($varAmt, 2)),                         ENT_QUOTES);
                $jStaff   = htmlspecialchars(json_encode($v['staff_name'] ?? 'System'),                      ENT_QUOTES);
                $jStaffId = htmlspecialchars(json_encode($v['staff_uid'] ?? '—'),                            ENT_QUOTES);
                $jDate    = htmlspecialchars(json_encode(date('M d, Y H:i', strtotime($v['created_at']))),   ENT_QUOTES);
                $jStatus  = htmlspecialchars(json_encode($status),                                           ENT_QUOTES);
                $jNotes   = htmlspecialchars(json_encode($v['investigation_notes'] ?? ''),                   ENT_QUOTES);
            ?>
            <tr <?= $isArch ? 'style="opacity:.6;"' : ''; ?>>
                <td style="font-weight:700;color:#002F6C;">#<?= $vid; ?><?= $isArch ? ' <span style="font-size:9px;background:#6c757d;color:#fff;padding:1px 5px;border-radius:8px;">ARCHIVED</span>' : ''; ?></td>
                <td><span class="va-type-badge type-<?= strtolower($txnType)==='job order'?'jo':'merch'; ?>"><?= strtolower($txnType)==='job order'?'JO':'MERCH'; ?></span></td>
                <td style="max-width:200px;word-break:break-word;"><?= htmlspecialchars($dispItem); ?></td>
                <td style="font-weight:700;color:<?= $varAmt>0?'#28a745':($varAmt<0?'#dc3545':'#28a745'); ?>;"><?= ($varAmt>0?'+':'').number_format($varAmt,2); ?></td>
                <td style="font-size:12px;color:#555;"><?= htmlspecialchars($v['staff_name']??'—'); ?></td>
                <td style="white-space:nowrap;font-size:12px;"><?= date('M d, Y H:i', strtotime($v['created_at'])); ?></td>
                <td><span class="status-badge badge-<?= $status; ?>"><?= $sm['label']; ?></span></td>
                <td>
                    <div class="actions-cell">
                        <!-- VIEW — always visible -->
                        <button type="button" class="btn-action btn-view"
                            onclick="openViewModal(<?= $jVid; ?>,<?= $jType; ?>,<?= $jItem; ?>,<?= $jVar; ?>,<?= $jStaff; ?>,<?= $jStaffId; ?>,<?= $jDate; ?>,<?= $jStatus; ?>,<?= $jNotes; ?>)">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <?php if (!$isArch && $status !== 'resolved'): ?>
                        <!-- INVESTIGATE -->
                        <button type="button" class="btn-action btn-view"
                            onclick="openInvestigateModal(<?= $jVid; ?>,<?= $jNotes; ?>)">
                            <i class="fas fa-search"></i> Investigate
                        </button>
                        <!-- RESOLVE -->
                        <button type="button" class="btn-action btn-approve"
                            onclick="openResolveModal(<?= $jVid; ?>,<?= $jNotes; ?>)">
                            <i class="fas fa-check"></i> Resolve
                        </button>
                        <?php endif; ?>
                        <?php if (!$isArch && $status !== 'escalated' && $status !== 'resolved'): ?>
                        <!-- ESCALATE -->
                        <button type="button" class="btn-action btn-reject"
                            onclick="openEscalateModal(<?= $jVid; ?>,<?= $jNotes; ?>)">
                            <i class="fas fa-arrow-up"></i> Escalate
                        </button>
                        <?php endif; ?>
                        <?php if (!$isArch && $status === 'resolved'): ?>
                        <!-- ARCHIVE -->
                        <button type="button" class="btn-action btn-archive"
                            onclick="openArchiveModal(<?= $jVid; ?>)">
                            <i class="fas fa-archive"></i> Archive
                        </button>
                        <?php endif; ?>
                        <?php if (!$isArch && in_array($status, ['resolved','escalated'])): ?>
                        <!-- RE-OPEN -->
                        <button type="button" class="btn-action btn-reopen"
                            onclick="openReopenModal(<?= $jVid; ?>)">
                            <i class="fas fa-undo"></i> Re-open
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     MODALS
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->

<!-- VIEW MODAL -->
<div id="viewModal" class="va-modal" onclick="if(event.target===this)closeModal('viewModal')">
    <div class="va-modal-box" style="max-width:600px;">
        <div class="va-modal-head" style="background:linear-gradient(135deg,#002F6C,#004aad);">
            <h3 style="color:#fff;"><i class="fas fa-eye"></i> Variance Alert Details</h3>
            <button class="va-modal-close" style="color:#fff;" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="va-modal-body"><div class="va-detail-grid" id="vm_grid"></div></div>
        <div class="va-modal-foot">
            <button type="button" class="va-btn va-btn-secondary" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- INVESTIGATE MODAL -->
<div id="investigateModal" class="va-modal" onclick="if(event.target===this)closeModal('investigateModal')">
    <div class="va-modal-box" style="max-width:500px;">
        <div class="va-modal-head">
            <h3><i class="fas fa-search"></i> Investigate Variance</h3>
            <button class="va-modal-close" onclick="closeModal('investigateModal')">&times;</button>
        </div>
        <form method="POST" id="investigateForm">
            <div class="va-modal-body">
                <input type="hidden" name="action" value="investigate_variance">
                <input type="hidden" id="inv_id" name="variance_id">
                <input type="hidden" name="_status" value="<?= htmlspecialchars($filter_status); ?>">
                <input type="hidden" name="_type"   value="<?= htmlspecialchars($filter_type); ?>">
                <input type="hidden" name="_date"   value="<?= htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="_q"      value="<?= htmlspecialchars($search_q); ?>">
                <p class="va-modal-hint"><i class="fas fa-info-circle" style="color:#0d6efd;"></i> Mark this alert as <strong>Investigating</strong> and record your initial findings.</p>
                <div class="va-form-group">
                    <label class="va-form-label">Investigation Notes <span class="va-req">*</span></label>
                    <textarea id="inv_notes" name="notes" class="va-textarea" rows="5"
                        placeholder="Describe what you found, what you are checking, or initial observations—¦"></textarea>
                    <div id="inv_notes_err" class="va-field-err" style="display:none;">Notes are required.</div>
                </div>
            </div>
            <div class="va-modal-foot">
                <button type="button" class="va-btn va-btn-primary" onclick="submitModal('investigateForm','inv_notes','inv_notes_err')">
                    <i class="fas fa-search"></i> Start Investigation
                </button>
                <button type="button" class="va-btn va-btn-secondary" onclick="closeModal('investigateModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- RESOLVE MODAL -->
<div id="resolveModal" class="va-modal" onclick="if(event.target===this)closeModal('resolveModal')">
    <div class="va-modal-box" style="max-width:500px;">
        <div class="va-modal-head">
            <h3><i class="fas fa-check-circle"></i> Resolve Variance Alert</h3>
            <button class="va-modal-close" onclick="closeModal('resolveModal')">&times;</button>
        </div>
        <form method="POST" id="resolveForm">
            <div class="va-modal-body">
                <input type="hidden" name="action" value="resolve_variance">
                <input type="hidden" id="res_id" name="variance_id">
                <input type="hidden" name="_status" value="<?= htmlspecialchars($filter_status); ?>">
                <input type="hidden" name="_type"   value="<?= htmlspecialchars($filter_type); ?>">
                <input type="hidden" name="_date"   value="<?= htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="_q"      value="<?= htmlspecialchars($search_q); ?>">
                <p class="va-modal-hint"><i class="fas fa-check-circle" style="color:#28a745;"></i> Provide a resolution summary. This closes the alert as <strong style="color:#28a745;">Resolved</strong>.</p>
                <div class="va-form-group">
                    <label class="va-form-label">Resolution Notes <span class="va-req">*</span></label>
                    <textarea id="res_notes" name="notes" class="va-textarea" rows="5"
                        placeholder="Describe how this variance was resolved, root cause, and corrective action taken—¦"></textarea>
                    <div id="res_notes_err" class="va-field-err" style="display:none;">Resolution notes are required.</div>
                </div>
            </div>
            <div class="va-modal-foot">
                <button type="button" class="va-btn va-btn-success" onclick="submitModal('resolveForm','res_notes','res_notes_err')">
                    <i class="fas fa-check"></i> Mark as Resolved
                </button>
                <button type="button" class="va-btn va-btn-secondary" onclick="closeModal('resolveModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ESCALATE MODAL -->
<div id="escalateModal" class="va-modal" onclick="if(event.target===this)closeModal('escalateModal')">
    <div class="va-modal-box" style="max-width:500px;">
        <div class="va-modal-head">
            <h3><i class="fas fa-arrow-up"></i> Escalate to Admin / Audit</h3>
            <button class="va-modal-close" onclick="closeModal('escalateModal')">&times;</button>
        </div>
        <form method="POST" id="escalateForm">
            <div class="va-modal-body">
                <input type="hidden" name="action" value="escalate_variance">
                <input type="hidden" id="esc_id" name="variance_id">
                <input type="hidden" name="_status" value="<?= htmlspecialchars($filter_status); ?>">
                <input type="hidden" name="_type"   value="<?= htmlspecialchars($filter_type); ?>">
                <input type="hidden" name="_date"   value="<?= htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="_q"      value="<?= htmlspecialchars($search_q); ?>">
                <p class="va-modal-hint"><i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i> This flags the alert as <strong style="color:#dc3545;">Escalated</strong> and forwards it to Admin/Audit oversight.</p>
                <div class="va-form-group">
                    <label class="va-form-label">Escalation Reason <span class="va-req">*</span></label>
                    <textarea id="esc_notes" name="notes" class="va-textarea" rows="5"
                        placeholder="Explain why this variance requires admin-level attention—¦"></textarea>
                    <div id="esc_notes_err" class="va-field-err" style="display:none;">Escalation reason is required.</div>
                </div>
            </div>
            <div class="va-modal-foot">
                <button type="button" class="va-btn va-btn-danger" onclick="submitModal('escalateForm','esc_notes','esc_notes_err')">
                    <i class="fas fa-arrow-up"></i> Escalate to Admin
                </button>
                <button type="button" class="va-btn va-btn-secondary" onclick="closeModal('escalateModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ARCHIVE MODAL -->
<div id="archiveModal" class="va-modal" onclick="if(event.target===this)closeModal('archiveModal')">
    <div class="va-modal-box" style="max-width:440px;">
        <div class="va-modal-head" style="background:linear-gradient(135deg,#495057,#6c757d);">
            <h3 style="color:#fff;"><i class="fas fa-archive"></i> Archive Resolved Alert</h3>
            <button class="va-modal-close" style="color:#fff;" onclick="closeModal('archiveModal')">&times;</button>
        </div>
        <form method="POST" id="archiveForm">
            <div class="va-modal-body">
                <input type="hidden" name="action" value="archive_variance">
                <input type="hidden" id="arch_id" name="variance_id">
                <input type="hidden" name="_status" value="<?= htmlspecialchars($filter_status); ?>">
                <input type="hidden" name="_type"   value="<?= htmlspecialchars($filter_type); ?>">
                <input type="hidden" name="_date"   value="<?= htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="_q"      value="<?= htmlspecialchars($search_q); ?>">
                <p class="va-modal-hint"><i class="fas fa-info-circle" style="color:#6c757d;"></i>
                    This will <strong>archive</strong> the resolved alert and hide it from the main view. You can still view archived alerts by checking <em>Show Archived</em> in the filter bar.</p>
            </div>
            <div class="va-modal-foot">
                <button type="submit" class="va-btn va-btn-secondary"><i class="fas fa-archive"></i> Confirm Archive</button>
                <button type="button" class="va-btn" style="background:#dee2e6;color:#212529;" onclick="closeModal('archiveModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- RE-OPEN MODAL -->
<div id="reopenModal" class="va-modal" onclick="if(event.target===this)closeModal('reopenModal')">
    <div class="va-modal-box" style="max-width:440px;">
        <div class="va-modal-head" style="background:linear-gradient(135deg,#842029,#dc3545);">
            <h3 style="color:#fff;"><i class="fas fa-undo"></i> Re-open Alert</h3>
            <button class="va-modal-close" style="color:#fff;" onclick="closeModal('reopenModal')">&times;</button>
        </div>
        <form method="POST" id="reopenForm">
            <div class="va-modal-body">
                <input type="hidden" name="action" value="reopen_variance">
                <input type="hidden" id="reopen_id" name="variance_id">
                <input type="hidden" name="_status" value="<?= htmlspecialchars($filter_status); ?>">
                <input type="hidden" name="_type"   value="<?= htmlspecialchars($filter_type); ?>">
                <input type="hidden" name="_date"   value="<?= htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="_q"      value="<?= htmlspecialchars($search_q); ?>">
                <p class="va-modal-hint"><i class="fas fa-exclamation-circle" style="color:#dc3545;"></i>
                    This will set the alert status back to <strong>Open</strong> for further investigation.</p>
            </div>
            <div class="va-modal-foot">
                <button type="submit" class="va-btn va-btn-danger"><i class="fas fa-undo"></i> Confirm Re-open</button>
                <button type="button" class="va-btn va-btn-secondary" onclick="closeModal('reopenModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- FLAG NEW ANOMALY MODAL -->
<div id="flagModal" class="va-modal" onclick="if(event.target===this)closeModal('flagModal')">
    <div class="va-modal-box" style="max-width:520px;">
        <div class="va-modal-head" style="background:linear-gradient(135deg,#842029,#dc3545);">
            <h3 style="color:#fff;"><i class="fas fa-flag"></i> Flag as Variance Alert</h3>
            <button class="va-modal-close" style="color:#fff;" onclick="closeModal('flagModal')">&times;</button>
        </div>
        <form method="POST" action="backend_flag_variance.php" id="flagForm">
            <div class="va-modal-body">
                <input type="hidden" name="action" value="flag_variance">
                <input type="hidden" id="flag_station_id" name="station_id" value="<?= (int)$station_id; ?>">
                <input type="hidden" id="flag_type"     name="transaction_type">
                <input type="hidden" id="flag_item"     name="item_identifier">
                <input type="hidden" id="flag_variance" name="variance_amount">
                <input type="hidden" id="flag_staff_id" name="user_id">
                <div class="va-detail-grid" id="flag_detail_grid" style="margin-bottom:16px;"></div>
                <div class="va-form-group">
                    <label class="va-form-label">Notes / Remarks <span class="va-req">*</span></label>
                    <textarea id="flag_notes" name="notes" class="va-textarea" rows="4"
                        placeholder="Describe the anomaly and why it is being flagged—¦"></textarea>
                    <div id="flag_notes_err" class="va-field-err" style="display:none;">Notes are required to flag this alert.</div>
                </div>
            </div>
            <div class="va-modal-foot">
                <button type="button" class="va-btn va-btn-danger" onclick="submitModal('flagForm','flag_notes','flag_notes_err')"><i class="fas fa-flag"></i> Flag Alert</button>
                <button type="button" class="va-btn va-btn-secondary" onclick="closeModal('flagModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     JAVASCRIPT
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<script>
// â”€â”€ Move all va-modals to <body> so they escape any fixed/overflow container â”€â”€
(function() {
    function moveModals() {
        document.querySelectorAll('.va-modal').forEach(function(m) {
            if (m.parentNode !== document.body) document.body.appendChild(m);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', moveModals);
    } else {
        moveModals();
    }
})();

// â”€â”€ Modal helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
function openModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.parentNode !== document.body) document.body.appendChild(el);
    el.style.display = 'flex';
}

// â”€â”€ View â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openViewModal(id, type, item, variance, staff, staffId, date, status, notes) {
    var sc = {open:'#002F70',investigating:'#002F70',resolved:'#28a745',escalated:'#dc3545'}[status]||'#6c757d';
    var typeColor = (type==='Job Order'||type==='job order') ? '#28a745' : '#002F70';
    var typeLabel = (type==='Job Order'||type==='job order') ? 'JO' : 'MERCH';
    var varColor  = parseFloat(variance) >= 0 ? '#28a745' : '#dc3545';
    document.getElementById('vm_grid').innerHTML =
        di('Alert ID',         '<strong style="color:#002F6C;">#'+esc(id)+'</strong>') +
        di('Transaction Type', '<span style="color:'+typeColor+';font-weight:700;font-size:12px;">'+esc(typeLabel)+'</span>') +
        '<div class="va-detail-item" style="grid-column:1/-1;">'+dl('Product / SKU / Service')+'<span class="va-detail-val">'+esc(item)+'</span></div>' +
        di('Variance Amount',  '<strong style="color:'+varColor+';">'+esc(variance)+'</strong>') +
        di('Staff',            esc(staff)+(staffId&&staffId!=='—'?' <span style="font-size:10px;color:#888;">(ID: '+esc(String(staffId))+')</span>':'')) +
        di('Date Flagged',     esc(date)) +
        di('Status',           '<span style="color:'+sc+';font-weight:700;font-size:12px;text-transform:uppercase;">'+esc(status.charAt(0).toUpperCase()+status.slice(1))+'</span>') +
        '<div class="va-detail-item" style="grid-column:1/-1;">'+dl('Investigation Notes')+'<span class="va-detail-val" style="white-space:pre-wrap;min-height:32px;">'+esc(notes||'(no notes yet)')+'</span></div>';
    openModal('viewModal');
}

// â”€â”€ Investigate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openInvestigateModal(id, notes) {
    document.getElementById('inv_id').value    = id;
    document.getElementById('inv_notes').value = notes || '';
    hideErr('inv_notes_err');
    openModal('investigateModal');
    setTimeout(function(){ document.getElementById('inv_notes').focus(); }, 150);
}

// â”€â”€ Resolve â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openResolveModal(id, notes) {
    document.getElementById('res_id').value    = id;
    document.getElementById('res_notes').value = notes || '';
    hideErr('res_notes_err');
    openModal('resolveModal');
    setTimeout(function(){ document.getElementById('res_notes').focus(); }, 150);
}

// â”€â”€ Escalate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openEscalateModal(id, notes) {
    document.getElementById('esc_id').value    = id;
    document.getElementById('esc_notes').value = notes || '';
    hideErr('esc_notes_err');
    openModal('escalateModal');
    setTimeout(function(){ document.getElementById('esc_notes').focus(); }, 150);
}

// â”€â”€ Archive â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openArchiveModal(id) {
    document.getElementById('arch_id').value = id;
    openModal('archiveModal');
}

// â”€â”€ Re-open â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openReopenModal(id) {
    document.getElementById('reopen_id').value = id;
    openModal('reopenModal');
}

// â”€â”€ Flag New Anomaly â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openFlagModal(a) {
    document.getElementById('flag_type').value     = a.type;
    document.getElementById('flag_item').value     = a.ref + ' — ' + a.item;
    document.getElementById('flag_variance').value = a.variance;
    document.getElementById('flag_staff_id').value = a.staff_id || '';
    document.getElementById('flag_notes').value    = '';
    var typeColor = (a.type==='Job Order') ? '#28a745' : '#002F70';
    var typeLabel = (a.type==='Job Order') ? 'JO' : 'MERCH';
    document.getElementById('flag_detail_grid').innerHTML =
        di('Type',    '<span style="color:'+typeColor+';font-weight:700;font-size:12px;">'+esc(typeLabel)+'</span>') +
        di('Anomaly', '<strong style="color:#842029;">'+esc(a.subtype)+'</strong>') +
        '<div class="va-detail-item" style="grid-column:1/-1;">'+dl('Reference / Item')+'<span class="va-detail-val">'+esc(a.ref+' — '+a.item)+'</span></div>' +
        di('Variance','<strong style="color:#dc3545;">'+parseFloat(a.variance||0).toFixed(2)+'</strong>') +
        di('Staff',   esc(a.staff)) +
        '<div class="va-detail-item" style="grid-column:1/-1;">'+dl('Description')+'<span class="va-detail-val">'+esc(a.description)+'</span></div>';
    openModal('flagModal');
    setTimeout(function(){ document.getElementById('flag_notes').focus(); }, 150);
}

// â”€â”€ Submit with validation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function submitModal(formId, notesFieldId, errId) {
    var notes = document.getElementById(notesFieldId).value.trim();
    if (!notes) { showErr(errId); document.getElementById(notesFieldId).focus(); return; }
    hideErr(errId);
    document.getElementById(formId).submit();
}

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function showErr(id){ var el=document.getElementById(id); if(el) el.style.display='block'; }
function hideErr(id){ var el=document.getElementById(id); if(el) el.style.display='none';  }
function di(label,valHtml){ return '<div class="va-detail-item">'+dl(label)+'<span class="va-detail-val">'+valHtml+'</span></div>'; }
function dl(label){ return '<span class="va-detail-lbl">'+esc(label)+'</span>'; }
function esc(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function applyFilter(key,val){
    var url=new URL(window.location.href);
    if(val==='') url.searchParams.delete(key); else url.searchParams.set(key,val);
    window.location.href=url.toString();
}

// â”€â”€ Escape key closes modals â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.addEventListener('keydown', function(e) {
    if (e.key==='Escape') ['viewModal','investigateModal','resolveModal','escalateModal','archiveModal','reopenModal','flagModal'].forEach(closeModal);
});

// â”€â”€ Clear error on textarea input â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.addEventListener('DOMContentLoaded', function() {
    [['inv_notes','inv_notes_err'],['res_notes','res_notes_err'],['esc_notes','esc_notes_err'],['flag_notes','flag_notes_err']].forEach(function(p){
        var ta=document.getElementById(p[0]);
        if(ta) ta.addEventListener('input', function(){ hideErr(p[1]); });
    });
});
</script>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     STYLES
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<style>
/* â”€â”€ Flash â”€â”€ */
.va-flash { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; font-weight:500; }
.va-flash-ok  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.va-flash-err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* â”€â”€ Header buttons â”€â”€ */
.va-hdr-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:7px; font-size:13px; font-weight:600; color:#fff; text-decoration:none; transition:filter .15s; }
.va-hdr-btn:hover { filter:brightness(.88); }

/* â”€â”€ Stat cards — white background, no colored icon circles â”€â”€ */
.va-stat-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
.va-stat-card { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid #e9ecef; border-radius:10px; padding:14px 18px; flex:1; transition:box-shadow .15s, transform .15s; }
.va-stat-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.1); transform:translateY(-2px); }
.va-stat-icon { width:40px; height:40px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; color:#002F70; }
.va-stat-num  { font-size:22px; font-weight:800; color:#002F70; line-height:1; }
.va-stat-lbl  { font-size:11px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }

/* â”€â”€ Anomaly banner â”€â”€ */
.va-anomaly-banner { background:#fff5f5; border:1px solid #f5c6cb; border-radius:10px; padding:16px 18px; margin-bottom:18px; }

/* â”€â”€ Filter bar â”€â”€ */
.va-filter-bar { background:#fff; border:1px solid #e9ecef; border-radius:10px; padding:16px 18px; margin-bottom:16px; }
.va-filter-group { display:flex; flex-direction:column; gap:4px; }
.va-filter-group label { font-size:11px; font-weight:700; color:#495057; text-transform:uppercase; letter-spacing:.4px; }
.va-select { padding:8px 12px; border:1px solid #ced4da; border-radius:6px; font-size:13px; background:#fff; transition:border-color .2s; }
.va-select:focus { outline:none; border-color:#002F70; box-shadow:0 0 0 2px rgba(0,47,112,.15); }

/* â”€â”€ Generic modal buttons â”€â”€ */
.va-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; transition:filter .15s; white-space:nowrap; }
.va-btn:hover { filter:brightness(.88); }
.va-btn-primary   { background:#002F70; color:#fff; }
.va-btn-secondary { background:#6c757d; color:#fff; }
.va-btn-success   { background:#28a745; color:#fff; }
.va-btn-danger    { background:#dc3545; color:#fff; }

/* â”€â”€ Main table — purchase-order style â”€â”€ */
.po-table-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow-x:auto;-webkit-overflow-scrolling:touch; }
.po-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
.po-table thead th { background:#002F70; color:#fff; padding:12px 14px; text-align:left; font-weight:600; }
.po-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.15s; }
.po-table tbody tr:hover { background:#f5f8ff; }
.po-table tbody td { padding:11px 14px; vertical-align:middle; color:#333; }

/* keep va-table for the anomaly banner inner table */
.va-table { width:100%; border-collapse:collapse; font-size:13px; }
.va-table th { background:#f8f9fa; color:#2c3e50; font-weight:700; padding:10px 14px; text-align:left; border-bottom:2px solid #dee2e6; white-space:nowrap; }
.va-table td { padding:10px 14px; border-bottom:1px solid #f0f2f5; vertical-align:middle; }
.va-table tbody tr:hover { background:#f8f9fa; }

/* â”€â”€ Status badges — plain text, no background â”€â”€ */
.status-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.badge-open          { color:#002F70; }
.badge-resolved      { color:#28a745; }
.badge-closed        { color:#6c757d; }
.badge-escalated     { color:#dc3545; }
.badge-investigating { color:#002F70; }
.badge-other         { color:#6c757d; }

/* â”€â”€ Type badges — plain text, no background â”€â”€ */
.va-type-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.type-merch { color:#002F70; }
.type-jo    { color:#28a745; }

/* â”€â”€ Action buttons — colored â”€â”€ */
.btn-action { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:0.82rem; font-weight:600; text-decoration:none; transition:opacity 0.2s; white-space:nowrap; margin-bottom:3px; }
.btn-action:hover { opacity:0.85; }
.btn-view    { background:#6c757d; color:#fff; }
.btn-archive { background:#002F70; color:#fff; }
.btn-reopen  { background:#dc3545; color:#fff; }
.btn-approve { background:#28a745; color:#fff; }
.btn-reject  { background:#dc3545; color:#fff; }
.actions-cell { display:flex; flex-direction:column; gap:4px; }
.actions-cell .btn-action { width:100%; justify-content:center; margin-bottom:0; }

/* â”€â”€ Anomaly banner flag button â”€â”€ */
.va-act-btn { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:5px 10px; border:none; border-radius:5px; font-size:11px; font-weight:700; cursor:pointer; color:#fff; transition:opacity .15s, transform .1s; }
.va-act-btn:hover  { opacity:.85; transform:translateY(-1px); }
.va-act-btn:active { transform:translateY(0); }
.va-act-flag { background:#dc3545; }

/* â”€â”€ Empty state â”€â”€ */
.va-empty-state { text-align:center; padding:48px 20px; }
.va-empty-icon  { font-size:48px; color:#28a745; margin-bottom:12px; opacity:.7; }
.va-empty-title { font-size:16px; font-weight:700; color:#002F6C; margin-bottom:6px; }
.va-empty-sub   { font-size:13px; color:#6c757d; max-width:400px; margin:0 auto; }

/* â”€â”€ Modals â”€â”€ */
.va-modal { display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,.55); align-items:center; justify-content:center; }
.va-modal-box { background:#fff; border-radius:12px; width:92%; max-width:560px; box-shadow:0 8px 32px rgba(0,0,0,.22); overflow:hidden; animation:vaIn .18s ease-out; }
@keyframes vaIn { from{opacity:0;transform:scale(.95) translateY(-10px)} to{opacity:1;transform:none} }
.va-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; border-bottom:2px solid #e9ecef; background:#fff; }
.va-modal-head h3 { margin:0; font-size:16px; color:#002F6C; text-transform:uppercase; }
.va-modal-close { background:none; border:none; font-size:26px; color:#6c757d; cursor:pointer; line-height:1; padding:0; }
.va-modal-close:hover { color:#212529; }
.va-modal-body { padding:22px 24px; }
.va-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 24px; background:#f8f9fa; border-top:1px solid #dee2e6; }
.va-modal-hint { margin:0 0 14px; font-size:13px; color:#555; display:flex; align-items:flex-start; gap:8px; }

/* â”€â”€ Detail grid â”€â”€ */
.va-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.va-detail-item { background:#f8f9fa; padding:10px 12px; border-radius:8px; border:1px solid #e9ecef; }
.va-detail-lbl  { display:block; font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.va-detail-val  { display:block; font-size:14px; color:#212529; word-break:break-word; }

/* â”€â”€ Form â”€â”€ */
.va-form-group { margin-bottom:16px; }
.va-form-label { display:block; font-weight:700; color:#495057; margin-bottom:6px; font-size:13px; }
.va-req { color:red; }
.va-field-err { color:#dc3545; font-size:12px; margin-top:5px; font-weight:600; }
.va-textarea { width:100%; padding:10px 12px; border:1px solid #ced4da; border-radius:6px; font-size:13px; resize:vertical; box-sizing:border-box; font-family:inherit; }
.va-textarea:focus { outline:none; border-color:#002F70; box-shadow:0 0 0 2px rgba(0,47,112,.15); }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
