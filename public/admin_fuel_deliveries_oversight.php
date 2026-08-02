<?php
// ============================================================
// Admin Fuel Deliveries Oversight
// Fetch Source: fuel_deliveries (staff-encoded â†’ manager-verified)
// ============================================================
$page_id = 'admin_fuel_deliveries_oversight';
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

// â”€â”€ Handle AJAX Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['ajax_action'])) {
    $action = $_GET['ajax_action'];
    $del_id = (int)($_GET['delivery_id'] ?? 0);

    if ($action === 'get_details') {
        try {
            $stmt = $pdo->prepare("SELECT fd.id, fd.delivery_date, fd.fuel_type, fd.supplier,
                fd.invoice_no, fd.delivery_liters, fd.tanker_number, fd.status,
                fd.notes, fd.created_at, fd.verified_at, NULL AS batch_id, fd.tank_assigned,
                fd.received_by, fd.verified_by,
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '), staff.username, 'Unknown') AS staff_name,
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '), mgr.username, '—') AS manager_name,
                s.name AS station_name
                FROM fuel_deliveries fd
                LEFT JOIN users staff ON fd.received_by = staff.id
                LEFT JOIN users mgr   ON fd.verified_by = mgr.id
                LEFT JOIN stations s ON fd.station_id = s.id
                WHERE fd.id = ?");
            $stmt->execute([$del_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($del) {
                json_response(['success' => true, 'data' => $del]);
            } else {
                json_response(['success' => false, 'message' => 'Delivery not found.']);
            }
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($action === 'get_audit') {
        try {
            $stmt = $pdo->prepare("SELECT al.*, 
                COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'System') AS username
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.entity_type = 'fuel_deliveries' AND al.entity_id = ?
                ORDER BY al.created_at DESC");
            $stmt->execute([$del_id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'data' => $logs]);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

// â”€â”€ Handle Post Actions (Reopen Delivery) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'reopen') {
        $del_id = (int)$_POST['delivery_id'];
        if ($del_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ?");
                $stmt->execute([$del_id]);
                $del = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($del) {
                    $up = $pdo->prepare("UPDATE fuel_deliveries SET status = 'Pending', verified_by = NULL, verified_at = NULL WHERE id = ?");
                    $up->execute([$del_id]);

                    write_audit_log(
                        $pdo,
                        'Reopened Delivery',
                        "Reopened fuel delivery ID: {$del['id']} (DR: {$del['invoice_no']})",
                        'fuel_deliveries',
                        $del_id,
                        'system',
                        'Success'
                    );

                    $_SESSION['success'] = "Delivery ID {$del['id']} reopened successfully.";
                } else {
                    $_SESSION['error'] = "Delivery not found.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Error reopening delivery: " . $e->getMessage();
            }
        }
        header("Location: admin_fuel_deliveries_oversight.php?" . http_build_query($_GET));
        exit;
    }
}

// â”€â”€ Station Filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : $station_id;
if ($role === 'superadmin' && !isset($_GET['station'])) {
    $filter_station = 0; // Default to all stations for superadmin
}

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from     = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to       = trim($_GET['date_to']   ?? date('Y-m-d'));
$fuel_type     = trim($_GET['fuel_type'] ?? '');
$status        = trim($_GET['status']    ?? '');
$dr_number     = trim($_GET['dr_number'] ?? '');
$tanker_number = trim($_GET['tanker_number'] ?? '');
$export        = trim($_GET['export']    ?? '');

// â”€â”€ Single Delivery Print Mode â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['single_id']) && $export === 'pdf') {
    $single_id = (int)$_GET['single_id'];
    try {
        $stmt = $pdo->prepare("SELECT fd.id, fd.delivery_date, fd.fuel_type, fd.supplier,
            fd.invoice_no, fd.delivery_liters, fd.tanker_number, fd.status,
            fd.notes, fd.created_at, fd.verified_at, NULL AS batch_id, fd.tank_assigned,
            fd.received_by, fd.verified_by,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '), staff.username, 'Unknown') AS staff_name,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '), mgr.username, '—') AS manager_name,
            s.name AS station_name
            FROM fuel_deliveries fd
            LEFT JOIN users staff ON fd.received_by = staff.id
            LEFT JOIN users mgr   ON fd.verified_by = mgr.id
            LEFT JOIN stations s ON fd.station_id = s.id
            WHERE fd.id = ?");
        $stmt->execute([$single_id]);
        $del = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($del) {
            $status_color = (strtolower($del['status']) === 'verified') ? '#16a34a' : ((strtolower($del['status']) === 'rejected') ? '#dc2626' : '#d97706');
            echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Delivery Ticket - #' . $del['id'] . '</title>
                <style>
                    body { font-family: "Courier New", Courier, monospace; font-size: 12px; line-height: 1.4; padding: 20px; color: #000; background: #fff; }
                    .ticket { max-width: 320px; margin: 0 auto; padding: 10px; border: 1px dashed #000; }
                    .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px; }
                    .header h2 { margin: 0; font-size: 16px; font-weight: bold; }
                    .header p { margin: 2px 0 0; font-size: 11px; }
                    .row { display: flex; justify-content: space-between; margin: 3px 0; }
                    .row label { font-weight: bold; }
                    .row span { text-align: right; }
                    .total { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 4px 0; margin: 6px 0; font-weight: bold; font-size: 13px; }
                    .footer { text-align: center; margin-top: 12px; font-size: 10px; border-top: 1px dashed #000; padding-top: 6px; }
                    .footer p { margin: 2px 0; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="no-print" style="margin-bottom: 15px; text-align: center;">
                    <button onclick="window.print()" style="padding: 6px 12px; font-size: 12px; cursor: pointer;">Print Ticket</button>
                    <button onclick="window.close()" style="padding: 6px 12px; font-size: 12px; cursor: pointer; margin-left: 5px;">Close</button>
                </div>
                <div class="ticket">
                    <div class="header">
                        <h2>PETRON</h2>
                        <p>' . htmlspecialchars($del['station_name'] ?? 'Petron Station') . '</p>
                        <p>Fuel Delivery Ticket</p>
                    </div>
                    <div class="row"><label>Delivery ID:</label><span>#' . $del['id'] . '</span></div>
                    <div class="row"><label>Batch ID:</label><span>' . htmlspecialchars($del['batch_id'] ?? '—') . '</span></div>
                    <div class="row"><label>Date:</label><span>' . date('M d, Y', strtotime($del['delivery_date'])) . '</span></div>
                    <div class="row"><label>Supplier:</label><span>' . htmlspecialchars($del['supplier']) . '</span></div>
                    <div class="row"><label>DR Number:</label><span>' . htmlspecialchars($del['invoice_no'] ?? '—') . '</span></div>
                    <div class="row"><label>Tanker No:</label><span>' . htmlspecialchars($del['tanker_number'] ?? '—') . '</span></div>
                    <div class="row"><label>Fuel Type:</label><span>' . htmlspecialchars($del['fuel_type']) . '</span></div>
                    <div class="row"><label>Assigned Tank:</label><span>' . htmlspecialchars($del['tank_assigned'] ?? '—') . '</span></div>
                    <div class="total"><label>Liters Delivered:</label><span>' . number_format($del['delivery_liters'], 2) . ' L</span></div>
                    <div class="row"><label>Staff Receiver:</label><span>' . htmlspecialchars($del['staff_name']) . '</span></div>
                    <div class="row"><label>Manager Verifier:</label><span>' . htmlspecialchars($del['manager_name']) . '</span></div>
                    <div class="row"><label>Status:</label><span style="color:' . $status_color . '; font-weight:bold;">' . ucfirst(htmlspecialchars($del['status'])) . '</span></div>
                    <div class="row"><label>Verified At:</label><span>' . ($del['verified_at'] ? date('M d, Y h:i A', strtotime($del['verified_at'])) : '—') . '</span></div>
                    <div class="row" style="flex-direction:column; align-items:flex-start;"><label>Remarks:</label><span style="text-align:left; font-weight: normal; margin-top: 3px; color:#555;">' . htmlspecialchars($del['notes'] ?? '—') . '</span></div>
                    <div class="footer">
                        <p>Petron Fuel Operations</p>
                        <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
                    </div>
                </div>
            </body>
            </html>';
            exit;
        }
    } catch (Exception $e) {
        echo "Error loading delivery details.";
        exit;
    }
}

// â”€â”€ Get Station Name â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$station_name = 'All Stations';
if ($filter_station > 0) {
    try {
        $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $sn->execute([$filter_station]);
        $station_name = $sn->fetchColumn() ?: 'Station';
    } catch (Exception $e) {}
}

// â”€â”€ Summary & Fetch Query Construction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$where  = ["DATE(fd.delivery_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_station > 0) {
    $where[] = "fd.station_id = ?";
    $params[] = $filter_station;
}
if ($fuel_type !== '') {
    $where[] = "fd.fuel_type = ?";
    $params[] = $fuel_type;
}
if ($status !== '') {
    $st_lower = strtolower($status);
    if ($st_lower === 'pending') {
        $where[] = "(LOWER(fd.status) IN ('pending', 'pending validation', 'pending manager validation', 'pending manager approval', '') OR fd.status IS NULL)";
    } elseif ($st_lower === 'verified') {
        $where[] = "LOWER(fd.status) IN ('verified', 'approved', 'validated')";
    } elseif ($st_lower === 'rejected') {
        $where[] = "LOWER(fd.status) IN ('rejected', 'discrepancy')";
    } else {
        $where[] = "LOWER(fd.status) = ?";
        $params[] = $st_lower;
    }
}
if ($dr_number !== '') {
    $where[] = "fd.invoice_no LIKE ?";
    $params[] = "%$dr_number%";
}
if ($tanker_number !== '') {
    $where[] = "fd.tanker_number LIKE ?";
    $params[] = "%$tanker_number%";
}

// â”€â”€ Summary Counts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total_deliveries = 0; $pending_deliveries = 0; $verified_deliveries = 0; $rejected_deliveries = 0; $total_liters = 0.0;
try {
    $sc_sql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(fd.status) IN ('pending', 'pending validation', 'pending manager validation', 'pending manager approval', '') OR fd.status IS NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(fd.status) IN ('verified', 'approved', 'validated') THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN LOWER(fd.status) IN ('rejected', 'discrepancy') THEN 1 ELSE 0 END) as rejected,
        COALESCE(SUM(CASE WHEN LOWER(fd.status) IN ('verified', 'approved', 'validated') THEN fd.delivery_liters ELSE 0 END), 0) as liters
        FROM fuel_deliveries fd
        WHERE " . implode(' AND ', $where);
    
    $sc = $pdo->prepare($sc_sql);
    $sc->execute($params);
    $sc_row = $sc->fetch(PDO::FETCH_ASSOC);
    $total_deliveries    = (int)($sc_row['total'] ?? 0);
    $pending_deliveries  = (int)($sc_row['pending'] ?? 0);
    $verified_deliveries = (int)($sc_row['verified'] ?? 0);
    $rejected_deliveries = (int)($sc_row['rejected'] ?? 0);
    $total_liters        = (float)($sc_row['liters'] ?? 0.0);
} catch (Exception $e) {}

// â”€â”€ Fetch Deliveries â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$deliveries = [];
try {
    $stmt = $pdo->prepare("SELECT fd.id, fd.delivery_date, fd.fuel_type, fd.supplier,
        fd.invoice_no, fd.delivery_liters, fd.tanker_number, fd.status,
        fd.notes, fd.created_at, fd.verified_at, NULL AS batch_id, fd.tank_assigned,
        COALESCE(NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '), staff.username, 'Unknown') AS received_by_name,
        COALESCE(NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '), mgr.username, '—') AS verified_by_name,
        s.name AS station_name
        FROM fuel_deliveries fd
        LEFT JOIN users staff ON fd.received_by = staff.id
        LEFT JOIN users mgr   ON fd.verified_by = mgr.id
        LEFT JOIN stations s ON fd.station_id = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY fd.delivery_date DESC, fd.id DESC LIMIT 500");
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Fuel Type list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$fuel_types = [];
try {
    if ($filter_station > 0) {
        $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_deliveries WHERE station_id=? ORDER BY fuel_type");
        $ft_stmt->execute([$filter_station]);
    } else {
        $ft_stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_deliveries ORDER BY fuel_type");
    }
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// â”€â”€ Get All Stations (for filter) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// â”€â”€ EXPORT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (in_array($export, ['csv','excel','pdf'])) {
    $headers = ['Delivery ID','Delivery Date','Delivery Ref','Station','Fuel Type','Assigned Tank','Supplier','DR Number','Liters Delivered','Tanker Number','Status','Remarks','Staff Receiver','Manager Verifier','Verification Date'];
    $rows_fmt = [];
    foreach($deliveries as $del) {
        $rows_fmt[] = [
            '#'.$del['id'],
            date('M d, Y', strtotime($del['delivery_date'])),
            $del['batch_id'] ?? '—',
            $del['station_name'] ?? '—',
            $del['fuel_type'],
            $del['tank_assigned'] ?? '—',
            $del['supplier'],
            $del['invoice_no'] ?? '—',
            number_format($del['delivery_liters'],2).' L',
            $del['tanker_number'] ?? '—',
            $del['status'],
            $del['notes'] ?? '—',
            $del['received_by_name'] ?? '—',
            $del['verified_by_name'] ?? '—',
            $del['verified_at'] ? date('M d, Y H:i', strtotime($del['verified_at'])) : '—',
        ];
    }
    $filename = 'fuel_deliveries_'.$date_from.'_to_'.$date_to;

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
        $out = fopen('php://output','w');
        fputs($out,"\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach($rows_fmt as $r) fputcsv($out, $r);
        fclose($out); exit;
    }
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Deliveries Oversight</h2><p>Period: '.$date_from.' to '.$date_to.' | Station: '.$station_name.' | Records: '.count($rows_fmt).'</p>';
        echo '<table><thead><tr>';
        foreach($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach($rows_fmt as $r) { echo '<tr>'; foreach($r as $c) echo '<td>'.htmlspecialchars($c).'</td>'; echo '</tr>'; }
        echo '</tbody></table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach($deliveries as $del) {
            $sc_color = (strtolower($del['status']) === 'verified') ? '#16a34a' : ((strtolower($del['status']) === 'rejected') ? '#dc2626' : '#d97706');
            $tbody .= '<tr>';
            $tbody .= '<td>#'.htmlspecialchars($del['id']).'</td>';
            $tbody .= '<td>'.date('M d, Y', strtotime($del['delivery_date'])).'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['batch_id'] ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['station_name'] ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['fuel_type']).'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['tank_assigned'] ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['supplier']).'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['invoice_no'] ?? '—').'</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;">'.number_format($del['delivery_liters'],2).' L</td>';
            $tbody .= '<td>'.htmlspecialchars($del['tanker_number'] ?? '—').'</td>';
            $tbody .= '<td style="color:'.$sc_color.';font-weight:700;">'.htmlspecialchars($del['status']).'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['received_by_name'] ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($del['verified_by_name'] ?? '—').'</td>';
            $tbody .= '<td>'.($del['verified_at'] ? date('M d, Y H:i', strtotime($del['verified_at'])) : '—').'</td>';
            $tbody .= '</tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Deliveries Oversight</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px}
        table{width:100%;border-collapse:collapse}
        th{background:#002F6C;color:#fff;padding:5px 6px;font-size:8px;text-transform:uppercase;text-align:left}
        td{padding:4px 6px;border-bottom:1px solid #e2e8f0;word-break:break-all}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer">Print</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none">â† Back</a></div>';
        echo '<div class="hdr"><h1>Fuel Deliveries Oversight</h1><p>Period: '.htmlspecialchars($date_from).' — '.htmlspecialchars($date_to).' | Station: '.htmlspecialchars($station_name).' | Records: '.count($deliveries).'</p></div>';
        echo '<table><thead><tr><th>ID</th><th>Date</th><th>Delivery Ref</th><th>Station</th><th>Fuel Type</th><th>Tank</th><th>Supplier</th><th>DR #</th><th>Liters</th><th>Tanker #</th><th>Status</th><th>Receiver</th><th>Verifier</th><th>Verified At</th></tr></thead>';
        echo '<tbody>'.($tbody ?: '<tr><td colspan="14" style="text-align:center;padding:20px;color:#94a3b8">No records.</td></tr>').'</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>
<style>
/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: 0 !important; padding-top: 16px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* Export/action buttons — unified outline style matching staff Transaction module */
.ato-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:0 16px; border-radius:7px; font-size:13px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s;
    height:36px; white-space:nowrap; background:white !important;
}
.ato-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-csv    { color:#003d7a !important; border-color:#003d7a !important; }
.ato-btn-csv:hover    { background:#003d7a !important; color:#fff !important; }
.ato-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-print  { color:#334155 !important; border-color:#64748b !important; }
.ato-btn-print:hover  { background:#64748b !important; color:#fff !important; }
.ato-btn-back   { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover   { background:#6b7280 !important; color:#fff !important; }
.ato-btn-filter { color:#002F70 !important; border-color:#002F70 !important; }
.ato-btn-filter:hover { background:#002F70 !important; color:#fff !important; }

/* Summary cards */
.afdo-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:24px; }
.afdo-card { background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:16px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,.05); position:relative; overflow:hidden; }
.afdo-card-info { display:flex; flex-direction:column; }
.afdo-card-lbl { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; }
.afdo-card-val { font-size:20px; font-weight:700; color:#1e293b; }
.afdo-card-icon { font-size:24px; opacity:0.8; }
.afdo-card.blue .afdo-card-icon { color:#2563eb; }
.afdo-card.yellow .afdo-card-icon { color:#d97706; }
.afdo-card.green .afdo-card-icon { color:#16a34a; }
.afdo-card.red .afdo-card-icon { color:#dc2626; }

/* Actions Stack */
.afto-btn-stack { display:flex; flex-direction:column; gap:4px; align-items:stretch; width:100%; }
.afto-row-btn {
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
    height:24px; padding:0 10px; border-radius:4px; font-size:11px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none;
    transition:all 0.15s ease-in-out; background:#ffffff !important; box-sizing:border-box; width:100%;
}
.afto-row-btn i { font-size:11px; }
.afto-row-btn-details { color:#1e40af !important; border-color:#bfdbfe !important; }
.afto-row-btn-details:hover { background:#eff6ff !important; border-color:#1e40af !important; }
.afto-row-btn-audit { color:#475569 !important; border-color:#cbd5e1 !important; }
.afto-row-btn-audit:hover { background:#f8fafc !important; border-color:#475569 !important; }
.afto-row-btn-print { color:#15803d !important; border-color:#bbf7d0 !important; }
.afto-row-btn-print:hover { background:#f0fdf4 !important; border-color:#15803d !important; }
.afto-row-btn-reopen { color:#ea580c !important; border-color:#ffedd5 !important; }
.afto-row-btn-reopen:hover { background:#fff7ed !important; border-color:#ea580c !important; }

/* Filter bar */
.afdo-filter { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.afdo-fg { display:flex; flex-direction:column; gap:3px; }
.afdo-fg label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.afdo-fg input, .afdo-fg select { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; color:#1e293b; background:#fff; outline:none; box-sizing:border-box; }
.afdo-fg input:focus, .afdo-fg select:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* Force no horizontal scroll */
html, body { max-width:100vw; overflow-x:hidden; }
.container { max-width:100%; overflow-x:hidden; }
.afdo-table-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); width:100%; }
.afdo-table-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.afdo-table-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.afdo-tbl-wrap { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.afdo-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:11px; }
.afdo-tbl thead tr { background:#002F70; }
.afdo-tbl thead th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; overflow:hidden; text-overflow:ellipsis; border-bottom:2px solid #001a3d; vertical-align:middle; }
.afdo-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.afdo-tbl tbody tr:hover td { background:#eff6ff; }
.afdo-tbl tbody td { padding:9px 10px; color:#334155; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; background:#fff; font-size:11px; }
.afdo-badge { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; white-space:nowrap; text-align:center; }
.bg-green { background:#f0fdf4; color:#166534; }
.bg-amber { background:#fef9c3; color:#a16207; }
.bg-red { background:#fef2f2; color:#b91c1c; }
.bg-blue { background:#eff6ff; color:#1d4ed8; }
.bg-gray { background:#f3f4f6; color:#374151; }
.afdo-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
.afdo-empty i { font-size:44px; display:block; margin-bottom:14px; opacity:.4; }

/* Modals */
@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
.details-item { display:flex; flex-direction:column; gap:2px; border-bottom:1px solid #f1f5f9; padding-bottom:8px; }
.details-item label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.details-item span { font-size:12px; font-weight:600; color:#1e293b; word-break:break-word; }
.audit-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px; }
.audit-meta { display:flex; justify-content:space-between; flex-wrap:wrap; gap:4px; font-size:10px; color:#64748b; margin-top:6px; border-top:1px dashed #e2e8f0; padding-top:4px; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-truck"></i> Fuel Deliveries Oversight</h1>
        <div class="sub">Oversee supplier fuel deliveries, cross-check against purchase orders, and flag discrepancies.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>" class="ato-btn ato-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>"   class="ato-btn ato-btn-csv"><i class="fas fa-file-csv"></i> CSV</a>
        <button type="button" onclick="exportFuelDeliveriesPdf()" class="ato-btn ato-btn-pdf"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <button type="button" onclick="printReportArea()" class="ato-btn ato-btn-print"><i class="fas fa-print"></i> Print</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="afdo-cards">
    <div class="afdo-card blue">
        <div class="afdo-card-info">
            <span class="afdo-card-lbl">Total Deliveries</span>
            <span class="afdo-card-val"><?= number_format($total_deliveries) ?></span>
        </div>
        <div class="afdo-card-icon"><i class="fas fa-list"></i></div>
    </div>
    <div class="afdo-card yellow">
        <div class="afdo-card-info">
            <span class="afdo-card-lbl">Pending Deliveries</span>
            <span class="afdo-card-val"><?= number_format($pending_deliveries) ?></span>
        </div>
        <div class="afdo-card-icon"><i class="fas fa-clock"></i></div>
    </div>
    <div class="afdo-card green">
        <div class="afdo-card-info">
            <span class="afdo-card-lbl">Verified Deliveries</span>
            <span class="afdo-card-val"><?= number_format($verified_deliveries) ?></span>
        </div>
        <div class="afdo-card-icon"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="afdo-card red">
        <div class="afdo-card-info">
            <span class="afdo-card-lbl">Rejected Deliveries</span>
            <span class="afdo-card-val"><?= number_format($rejected_deliveries) ?></span>
        </div>
        <div class="afdo-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
    <div class="afdo-card green">
        <div class="afdo-card-info">
            <span class="afdo-card-lbl">Total Liters Delivered</span>
            <span class="afdo-card-val"><?= number_format($total_liters, 2) ?> L</span>
        </div>
        <div class="afdo-card-icon"><i class="fas fa-gas-pump"></i></div>
    </div>
</div>

<!-- Filter Bar -->
<form method="get" class="afdo-filter">
    <?php if ($role === 'superadmin' && !empty($stations)): ?>
    <div class="afdo-fg">
        <label>Station</label>
        <select name="station">
            <option value="0" <?= $filter_station == 0 ? 'selected' : '' ?>>All Stations</option>
            <?php foreach ($stations as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_station == $s['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="afdo-fg">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    <div class="afdo-fg">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    <div class="afdo-fg">
        <label>Fuel Type</label>
        <select name="fuel_type">
            <option value="">All Fuel Types</option>
            <?php foreach($fuel_types as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type===$ft?'selected':'' ?>><?= htmlspecialchars($ft) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="afdo-fg">
        <label>Status</label>
        <select name="status">
            <option value="">All Statuses</option>
            <option value="Pending" <?= strtolower($status) === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Verified" <?= strtolower($status) === 'verified' ? 'selected' : '' ?>>Verified</option>
            <option value="Rejected" <?= strtolower($status) === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    <div class="afdo-fg">
        <label>DR Number</label>
        <input type="text" name="dr_number" value="<?= htmlspecialchars($dr_number) ?>" placeholder="Search DR #">
    </div>
    <div class="afdo-fg">
        <label>Tanker Number</label>
        <input type="text" name="tanker_number" value="<?= htmlspecialchars($tanker_number) ?>" placeholder="Search Tanker #">
    </div>
    <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_fuel_deliveries_oversight.php" class="ato-btn ato-btn-back"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table -->
<div class="afdo-table-card">
    <div class="afdo-table-hd">
        <h3 class="afdo-table-title"><i class="fas fa-table"></i> Fuel Delivery Records</h3>
        <span style="font-size:11px;color:#64748b;"><?= number_format(count($deliveries)) ?> record(s) — <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?></span>
    </div>
    <div class="afdo-tbl-wrap">
        <table class="afdo-tbl">
            <colgroup>
                <col style="width: 4%;">  <!-- ID -->
                <col style="width: 7%;">  <!-- Date -->
                <col style="width: 7%;">  <!-- Batch ID -->
                <col style="width: 7%;">  <!-- Supplier -->
                <col style="width: 7%;">  <!-- DR Number -->
                <col style="width: 6%;">  <!-- Tanker Number -->
                <col style="width: 7%;">  <!-- Fuel Type -->
                <col style="width: 6%;">  <!-- Assigned Tank -->
                <col style="width: 7%;">  <!-- Liters Delivered -->
                <col style="width: 7%;">  <!-- Staff Receiver -->
                <col style="width: 7%;">  <!-- Manager Verifier -->
                <col style="width: 6%;">  <!-- Status -->
                <col style="width: 7%;">  <!-- Verification Date -->
                <col style="width: 8%;">  <!-- Remarks -->
            </colgroup>
            <thead>
                <tr>
                    <th>Delivery ID</th>
                    <th>Delivery Date</th>
                    <th>Batch ID</th>
                    <th>Supplier</th>
                    <th>DR Number</th>
                    <th>Tanker Number</th>
                    <th>Fuel Type</th>
                    <th>Assigned Tank</th>
                    <th>Liters Delivered</th>
                    <th>Staff Receiver</th>
                    <th>Manager Verifier</th>
                    <th>Status</th>
                    <th>Verification Date</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deliveries)): ?>
                <tr>
                    <td colspan="14" style="text-align:center;padding:60px 20px;">
                        <i class="fas fa-inbox" style="font-size:48px;color:#cbd5e1;margin-bottom:16px;display:block;"></i>
                        <div style="font-size:16px;font-weight:700;color:#64748b;margin-bottom:8px;">No deliveries found</div>
                        <div style="font-size:14px;color:#94a3b8;">No fuel deliveries for the selected period.</div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($deliveries as $del):
                    $st = strtolower($del['status'] ?? '');
                    if ($st === 'verified') {
                        $badge = 'bg-green';
                        $st_label = 'Verified';
                    } elseif ($st === 'rejected') {
                        $badge = 'bg-red';
                        $st_label = 'Rejected';
                    } else {
                        $badge = 'bg-amber';
                        $st_label = 'Pending';
                    }
                ?>
                <tr>
                    <td style="color:#475569; font-weight: 600;">#<?= $del['id'] ?></td>
                    <td title="<?= date('Y-m-d', strtotime($del['delivery_date'])) ?>"><?= date('M d, Y', strtotime($del['delivery_date'])) ?></td>
                    <td title="<?= htmlspecialchars($del['batch_id'] ?? '—') ?>"><?= htmlspecialchars($del['batch_id'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($del['supplier'] ?? '—') ?>"><?= htmlspecialchars($del['supplier'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($del['invoice_no'] ?? '—') ?>"><?= htmlspecialchars($del['invoice_no'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($del['tanker_number'] ?? '—') ?>"><?= htmlspecialchars($del['tanker_number'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($del['fuel_type'] ?? '—') ?>"><?= htmlspecialchars($del['fuel_type'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($del['tank_assigned'] ?? '—') ?>"><?= htmlspecialchars($del['tank_assigned'] ?? '—') ?></td>
                    <td title="<?= number_format($del['delivery_liters'], 2) ?> L" style="font-weight:700; text-align: right;"><?= number_format($del['delivery_liters'], 2) ?> L</td>
                    <td title="<?= htmlspecialchars($del['received_by_name'] ?? '—') ?>"><?= htmlspecialchars($del['received_by_name'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($del['verified_by_name'] ?? '—') ?>"><?= htmlspecialchars($del['verified_by_name'] ?? '—') ?></td>
                    <td><span class="afdo-badge <?= $badge ?>"><?= $st_label ?></span></td>
                    <td title="<?= $del['verified_at'] ? date('Y-m-d H:i:s', strtotime($del['verified_at'])) : '—' ?>">
                        <?= $del['verified_at'] ? date('M d, Y H:i', strtotime($del['verified_at'])) : '—' ?>
                    </td>
                    <td title="<?= htmlspecialchars($del['notes'] ?? '') ?>"><?= htmlspecialchars($del['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Controls -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
            <select id="rowsPerPage" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="pageInfo" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
            <div style="display:flex;gap:4px;">
                <button id="prevPage" class="afdo-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                    <i class="fas fa-chevron-left"></i> Prev
                </button>
                <button id="nextPage" class="afdo-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function exportFuelDeliveriesPdf() {
    const rows = Array.from(document.querySelectorAll('.afdo-tbl tbody tr'));
    const originalDisplay = rows.map(row => row.style.display);
    rows.forEach(row => { row.style.display = ''; });
    exportPrintableAreaToPDF(
        '.afdo-table-card',
        'Fuel Deliveries Oversight',
        'admin_fuel_deliveries_<?= htmlspecialchars($date_from) ?>_to_<?= htmlspecialchars($date_to) ?>',
        document.activeElement
    );
    rows.forEach((row, index) => { row.style.display = originalDisplay[index]; });
}

// Pagination functionality
(function() {
    const table = document.querySelector('.afdo-tbl tbody');
    if (!table) return;
    
    const allRows = Array.from(table.querySelectorAll('tr'));
    let currentPage = 1;
    let rowsPerPage = 20;
    
    const rowsSelect = document.getElementById('rowsPerPage');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    
    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        
        // Hide all rows first
        allRows.forEach(row => row.style.display = 'none');
        
        // Show only current page rows
        allRows.slice(start, end).forEach(row => row.style.display = '');
        
        // Update page info
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        
        // Update button states
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
        
        // Update button styles
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    rowsSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        updateTable();
    });
    
    prevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
            // Scroll to top of table
            document.querySelector('.afdo-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            // Scroll to top of table
            document.querySelector('.afdo-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    // Add hover effects
    document.querySelectorAll('.afdo-page-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            if (!this.disabled) {
                this.style.background = '#f1f5f9';
                this.style.borderColor = '#cbd5e1';
            }
        });
        btn.addEventListener('mouseleave', function() {
            this.style.background = '#fff';
            this.style.borderColor = '#e2e8f0';
        });
    });
    
    // Initialize
    updateTable();
})();

// Modal helpers
function openModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.style.display = 'none';
        if (!document.querySelector('.ato-modal[style*="display: flex"]')) {
            document.body.style.overflow = '';
        }
    }
}
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('ato-modal')) {
        closeModal(e.target.id);
    }
});

// View Details Action
function viewDelDetails(id) {
    fetch(`?ajax_action=get_details&delivery_id=${id}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const data = res.data;
                const remarks = data.notes ? data.notes.replace(/\n/g, '<br>') : '—';
                const gridHtml = `
                    <div class="details-item"><label>Delivery ID</label><span>#${data.id}</span></div>
                    <div class="details-item"><label>Batch ID</label><span>${data.batch_id || '—'}</span></div>
                    <div class="details-item"><label>Delivery Date</label><span>${data.delivery_date}</span></div>
                    <div class="details-item"><label>Supplier</label><span>${data.supplier || '—'}</span></div>
                    <div class="details-item"><label>DR Number</label><span>${data.invoice_no || '—'}</span></div>
                    <div class="details-item"><label>Tanker Number</label><span>${data.tanker_number || '—'}</span></div>
                    <div class="details-item"><label>Fuel Type</label><span>${data.fuel_type || '—'}</span></div>
                    <div class="details-item"><label>Assigned Tank</label><span>${data.tank_assigned || '—'}</span></div>
                    <div class="details-item"><label>Liters Delivered</label><span>${parseFloat(data.delivery_liters).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} L</span></div>
                    <div class="details-item"><label>Staff Receiver</label><span>${data.received_by_name || '—'}</span></div>
                    <div class="details-item"><label>Manager Verifier</label><span>${data.verified_by_name || '—'}</span></div>
                    <div class="details-item"><label>Status</label><span>${data.status || '—'}</span></div>
                    <div class="details-item"><label>Verification Date</label><span>${data.verified_at || '—'}</span></div>
                    <div class="details-item" style="grid-column: span 2;"><label>Remarks</label><span>${remarks}</span></div>
                `;
                document.getElementById('detailsGrid').innerHTML = gridHtml;
                openModal('detailsModal');
            } else {
                alert(res.message || 'Error loading details.');
            }
        })
        .catch(err => alert('Network error.'));
}

// View History/Audit Action
function viewDelAudit(id) {
    fetch(`?ajax_action=get_audit&delivery_id=${id}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                const logs = res.data;
                if (logs.length === 0) {
                    document.getElementById('auditList').innerHTML = '<div style="text-align:center;color:#64748b;padding:20px;">No verification history records found.</div>';
                } else {
                    const listHtml = logs.map(log => `
                        <div class="audit-card">
                            <div style="font-weight:700;color:#00264D;font-size:12px;">${log.action_type}</div>
                            <div style="margin-top:4px;color:#334155;font-size:11px;">${log.action_details}</div>
                            <div class="audit-meta">
                                <span><i class="fas fa-user"></i> ${log.username}</span>
                                <span><i class="fas fa-clock"></i> ${new Date(log.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                    `).join('');
                    document.getElementById('auditList').innerHTML = listHtml;
                }
                openModal('auditModal');
            } else {
                alert(res.message || 'Error loading history.');
            }
        })
        .catch(err => alert('Network error.'));
}

// Print Record Action
function printSingleDel(id) {
    const printUrl = `?export=pdf&single_id=${id}`;
    let iframe = document.getElementById('print-iframe');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'print-iframe';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        document.body.appendChild(iframe);
    }
    iframe.src = printUrl;
    iframe.onload = function() {
        setTimeout(function() {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 300);
    };
}

// Reopen Action
function reopenDel(id, dr) {
    if (confirm(`Are you sure you want to reopen delivery with DR Number: ${dr}?\nThis will reset status back to 'Pending'.`)) {
        document.getElementById('reopenDelId').value = id;
        document.getElementById('reopenForm').submit();
    }
}
</script>

<!-- Details Modal -->
<div id="detailsModal" class="ato-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
    <div class="ato-modal-content" style="background-color:#fff; margin:auto; padding:20px; border:1px solid #cbd5e1; border-radius:12px; width:90%; max-width:600px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); animation:modalFadeIn 0.2s ease-out;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:#00264D; text-transform:uppercase;"><i class="fas fa-info-circle"></i> Delivery Details</h3>
            <button onclick="closeModal('detailsModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; transition:color 0.15s;">&times;</button>
        </div>
        <div id="detailsGrid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
            <!-- Dynamically populated -->
        </div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e8f0; padding-top:12px;">
            <button onclick="closeModal('detailsModal')" class="ato-btn ato-btn-back" style="height:32px; padding:0 12px; font-size:12px; font-weight:600;">Close</button>
        </div>
    </div>
</div>

<!-- Audit Modal -->
<div id="auditModal" class="ato-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
    <div class="ato-modal-content" style="background-color:#fff; margin:auto; padding:20px; border:1px solid #cbd5e1; border-radius:12px; width:90%; max-width:600px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); animation:modalFadeIn 0.2s ease-out;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:#00264D; text-transform:uppercase;"><i class="fas fa-history"></i> Verification History</h3>
            <button onclick="closeModal('auditModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; transition:color 0.15s;">&times;</button>
        </div>
        <div id="auditList" style="max-height:300px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding-right:5px;">
            <!-- Dynamically populated -->
        </div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e8f0; padding-top:12px;">
            <button onclick="closeModal('auditModal')" class="ato-btn ato-btn-back" style="height:32px; padding:0 12px; font-size:12px; font-weight:600;">Close</button>
        </div>
    </div>
</div>

<!-- Reopen Hidden Form -->
<form id="reopenForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="reopen">
    <input type="hidden" id="reopenDelId" name="delivery_id">
</form>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
