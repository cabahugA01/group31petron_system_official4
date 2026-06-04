<?php
/**
 * EXPORT STAFF FUEL DATA
 * type: fuel_transactions | fuel_deliveries
 * format: excel | csv | pdf
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';
session_start();
if (!isset($_SESSION['user_id'])) { die('Unauthorized'); }

$me         = current_user();
$station_id = (int)user_station_id();
$staff_id   = (int)$me['id'];
$type       = trim($_GET['type']   ?? 'fuel_transactions');
$format     = trim($_GET['format'] ?? 'csv');

if (!in_array($type,   ['fuel_transactions','fuel_deliveries'])) die('Invalid type');
if (!in_array($format, ['excel','csv','pdf']))                   die('Invalid format');

/* ── Data fetch ─────────────────────────────────────────────── */
if ($type === 'fuel_transactions') {
    $data = [];
    try {
        $stmt = $pdo->prepare("
            SELECT ft.id,
                   ft.fuel_type,
                   ft.previous_reading,
                   ft.present_reading,
                   ROUND(ft.present_reading - ft.previous_reading, 3) AS liters_sold,
                   ft.price_per_liter,
                   ROUND((ft.present_reading - ft.previous_reading) * ft.price_per_liter, 2) AS total_amount,
                   COALESCE(ft.calibration_factor, 0) AS calibration,
                   ft.shift_period,
                   ft.notes,
                   ft.status,
                   ft.transaction_date,
                   u.name AS encoded_by
            FROM fuel_transactions ft
            LEFT JOIN users u ON u.id = ft.staff_id
            WHERE ft.station_id = ? AND ft.staff_id = ?
            ORDER BY ft.transaction_date DESC
        ");
        $stmt->execute([$station_id, $staff_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data = []; }

    $filename = 'fuel_meter_readings_' . date('Y-m-d_His');
    $title    = 'Today\'s Meter Reading Report';
    $headers  = ['ID','Fuel Type','Prev Reading','Present Reading','Liters Sold','Price/Liter','Total Amount','Calibration','Shift','Notes','Status','Date','Encoded By'];
    $rows_fmt = [];
    foreach ($data as $r) {
        $rows_fmt[] = [
            'FT-'.$r['id'],
            $r['fuel_type'],
            number_format((float)$r['previous_reading'],3),
            number_format((float)$r['present_reading'],3),
            number_format((float)$r['liters_sold'],3),
            '₱'.number_format((float)$r['price_per_liter'],2),
            '₱'.number_format((float)$r['total_amount'],2),
            $r['calibration'],
            $r['shift_period'] ?: 'General',
            $r['notes'] ?: '—',
            $r['status'] ?: 'Pending',
            date('M d, Y H:i', strtotime($r['transaction_date'])),
            $r['encoded_by'] ?: 'Staff',
        ];
    }
} else {
    $data = [];
    try {
        $stmt = $pdo->prepare("
            SELECT fd.id,
                   fd.delivery_date,
                   fd.fuel_type,
                   fd.supplier,
                   fd.invoice_no,
                   fd.delivery_liters,
                   fd.tanker_number,
                   fd.notes,
                   fd.status,
                   fd.created_at,
                   u.name AS recorded_by
            FROM fuel_deliveries fd
            LEFT JOIN users u ON u.id = fd.received_by
            WHERE fd.station_id = ? AND fd.received_by = ?
            ORDER BY fd.delivery_date DESC, fd.created_at DESC
        ");
        $stmt->execute([$station_id, $staff_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data = []; }

    $filename = 'fuel_deliveries_' . date('Y-m-d_His');
    $title    = 'My Delivery Records Report';
    $headers  = ['ID','Delivery Date','Fuel Type','Supplier','Invoice No.','Qty (Liters)','Tanker No.','Notes','Status','Recorded At','Recorded By'];
    $rows_fmt = [];
    foreach ($data as $r) {
        $rows_fmt[] = [
            'DEL-'.$r['id'],
            date('M d, Y', strtotime($r['delivery_date'])),
            $r['fuel_type'],
            $r['supplier'],
            $r['invoice_no'],
            number_format((float)$r['delivery_liters'],3).' L',
            $r['tanker_number'] ?: '—',
            $r['notes'] ?: '—',
            $r['status'] ?: 'Pending',
            date('M d, Y H:i', strtotime($r['created_at'])),
            $r['recorded_by'] ?: 'Staff',
        ];
    }
}

/* ── CSV ─────────────────────────────────────────────────────── */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, $headers);
    foreach ($rows_fmt as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

/* ── Excel ───────────────────────────────────────────────────── */
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'.xls"');
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:7px}th{background:#002F70;color:#fff;font-weight:bold}</style></head><body>';
    echo '<h2>'.htmlspecialchars($title).'</h2><p>Generated: '.date('F d, Y h:i A').'</p><table><thead><tr>';
    foreach ($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows_fmt as $row) {
        echo '<tr>';
        foreach ($row as $val) echo '<td>'.htmlspecialchars((string)$val).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

/* ── PDF (print-friendly HTML) ───────────────────────────────── */
$logo_url  = '../assets/img/Petron%20Logo.png';
$generated = date('F d, Y  h:i A');
$total_rec = count($rows_fmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> | Petron SMS</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;margin:0;padding:0;background:#f1f5f9;color:#1e293b}
.action-bar{background:#002F70;padding:12px 24px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:999}
.action-bar h2{color:#fff;font-size:15px;margin:0;flex:1}
.btn-print{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;background:#DC0032;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.35);border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.report{background:#fff;max-width:1100px;margin:20px auto;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.12)}
.rpt-hd{background:linear-gradient(135deg,#002F70,#003d8a);padding:22px 28px;display:flex;align-items:center;gap:18px}
.rpt-hd img{height:52px;width:auto}
.rpt-hd-text h1{color:#fff;font-size:18px;font-weight:800;margin:0 0 3px}
.rpt-hd-text p{color:#93c5fd;font-size:11px;margin:0}
.rpt-hd-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:11px;line-height:1.7}
.rpt-hd-meta strong{color:#fff}
.rpt-body{padding:20px}
table{width:100%;border-collapse:collapse;font-size:11px}
thead tr{background:#002F70}
th{padding:9px 8px;color:#fff;font-weight:700;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
td{padding:8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
tr:nth-child(even) td{background:#f8fafc}
.rpt-footer{padding:16px 28px;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:10px;color:#64748b;text-align:center}
@media print{.action-bar{display:none!important}body{background:#fff;margin:0}.report{box-shadow:none;border-radius:0;margin:0;max-width:100%}}
</style>
</head>
<body>
<div class="action-bar">
  <h2>&#128438; <?= htmlspecialchars($title) ?></h2>
  <a href="javascript:window.print()" class="btn-print">&#128438; Print / Save as PDF</a>
  <a href="javascript:void(0)" onclick="window.history.length>1?window.history.back():window.close()" class="btn-back">&#8592; Back</a>
</div>
<div class="report">
  <div class="rpt-hd">
    <img src="<?= $logo_url ?>" alt="Petron Logo">
    <div class="rpt-hd-text">
      <h1>Petron Station Management System</h1>
      <p><?= htmlspecialchars($title) ?></p>
    </div>
    <div class="rpt-hd-meta">
      <div><strong>Generated:</strong> <?= $generated ?></div>
      <div><strong>Total Records:</strong> <?= $total_rec ?></div>
    </div>
  </div>
  <div class="rpt-body">
    <table>
      <thead><tr><?php foreach ($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>'; ?></tr></thead>
      <tbody>
        <?php foreach ($rows_fmt as $row): ?>
        <tr><?php foreach ($row as $val) echo '<td>'.htmlspecialchars((string)$val).'</td>'; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="rpt-footer">&copy; <?= date('Y') ?> Petron Station &amp; Service Center Management System. All Rights Reserved.</div>
</div>
</body>
</html>
<?php exit; ?>
