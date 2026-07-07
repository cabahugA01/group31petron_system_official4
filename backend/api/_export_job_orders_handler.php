<?php
/**  * Job Orders Export Handler  * Included by manager_reports_api.php for the 'export_job_orders' action.  * Expects: $pdo, $station_id, $date_from, $date_to, $_GET['format']  */  $fmt = $_GET['format'] ?? 'csv';  // ── Fetch data ────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("  SELECT  jo.id,  COALESCE(c.name, jo.customer_name, 'Walk-in') AS customer,  jo.service_type,  jo.service_description,  jo.status,  COALESCE(jo.estimated_cost, 0)  AS cost,  jo.created_at,  u.name  AS staff_name,  u.role  AS staff_role,  m.name  AS mechanic_name  FROM job_orders jo  LEFT JOIN customers c  ON c.id  = jo.customer_id  LEFT JOIN users u  ON u.user_id  = COALESCE(jo.created_by, jo.user_id)  LEFT JOIN users m  ON m.user_id  = jo.assigned_mechanic_id  WHERE jo.station_id = ?  AND DATE(jo.created_at) BETWEEN ? AND ?  ORDER BY jo.created_at DESC  LIMIT 1000
");
$stmt->execute([$station_id, $date_from, $date_to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);  // ── Status counts ─────────────────────────────────────────────────────────────
$cnt_stmt = $pdo->prepare("  SELECT status, COUNT(*) AS cnt, COALESCE(SUM(estimated_cost),0) AS total_cost  FROM job_orders WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?  GROUP BY status
");
$cnt_stmt->execute([$station_id, $date_from, $date_to]);
$counts = $cnt_stmt->fetchAll(PDO::FETCH_ASSOC);  // ── Station name ──────────────────────────────────────────────────────────────
$station_name = 'Station #' . $station_id;
try {  $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");  $sn->execute([$station_id]);  $station_name = $sn->fetchColumn() ?: $station_name;
} catch (Exception $e) {}  $filename = 'job_orders_report_' . $date_from . '_to_' . $date_to;  // ════════════════════════════════════════════════════════════════════════════
// CSV / EXCEL
// ════════════════════════════════════════════════════════════════════════════
if ($fmt === 'csv') {  header('Content-Type: text/csv; charset=UTF-8');  header('Content-Disposition: attachment; filename="' . $filename . '.csv"');  header('Cache-Control: no-cache, no-store, must-revalidate');  $out = fopen('php://output', 'w');  fputs($out, "\xEF\xBB\xBF");  fputcsv($out, ['Job Orders Report']);  fputcsv($out, ['Station:', $station_name]);  fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);  fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);  fputcsv($out, []);  // Status summary  fputcsv($out, ['STATUS SUMMARY']);  fputcsv($out, ['Status', 'Count', 'Total Cost (PHP)']);  foreach ($counts as $c) {  fputcsv($out, [$c['status'], $c['cnt'], number_format((float)$c['total_cost'], 2)]);  }  fputcsv($out, []);  // Detail  fputcsv($out, ['JOB ORDERS DETAIL']);  fputcsv($out, ['#', 'Customer', 'Service Type', 'Description', 'Status', 'Cost (PHP)', 'Staff', 'Mechanic / Technician', 'Date & Time']);  foreach ($rows as $r) {  fputcsv($out, [  $r['id'],  $r['customer'],  $r['service_type'],  $r['service_description'],  $r['status'],  number_format((float)$r['cost'], 2),  $r['staff_name'],  $r['mechanic_name'],  $r['created_at'],  ]);  }  if ($rows) {  fputcsv($out, ['', '', '', '', 'TOTAL', number_format(array_sum(array_column($rows, 'cost')), 2), '', '', '']);  }  fclose($out);  exit;
}  // ════════════════════════════════════════════════════════════════════════════
// PDF
// ════════════════════════════════════════════════════════════════════════════
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');  $status_colors = [  'pending' => '#92400e', 'pending validation' => '#92400e',  'approved' => '#166534', 'validated' => '#166534', 'verified' => '#166534',  'rejected' => '#991b1b', 'cancelled' => '#991b1b',  'adjusted' => '#1e40af',  'completed' => '#065f46',  'in progress' => '#5b21b6', 'in_progress' => '#5b21b6',
];
$status_bg = [  'pending' => '#fef3c7', 'pending validation' => '#fef3c7',  'approved' => '#dcfce7', 'validated' => '#dcfce7', 'verified' => '#dcfce7',  'rejected' => '#fee2e2', 'cancelled' => '#fee2e2',  'adjusted' => '#dbeafe',  'completed' => '#d1fae5',  'in progress' => '#ede9fe', 'in_progress' => '#ede9fe',
];  $tbody = '';
$grand_total = 0;
foreach ($rows as $r) {  $s = strtolower($r['status'] ?? '');  $sc = $status_colors[$s] ?? '#475569';  $sb = $status_bg[$s]  ?? '#f1f5f9';  $cost = (float)$r['cost'];  $grand_total += $cost;  $tbody .= '<tr>  <td style="color:#94a3b8;font-size:10px;">#' . (int)$r['id'] . '</td>  <td><strong>' . htmlspecialchars($r['customer'] ?? '—') . '</strong></td>  <td>' . htmlspecialchars($r['service_type'] ?? '—') . '</td>  <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . htmlspecialchars($r['service_description'] ?? '—') . '</td>  <td><span style="background:' . $sb . ';color:' . $sc . ';padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600;">' . htmlspecialchars($r['status'] ?? '—') . '</span></td>  <td class="tr"><strong>&#8369;' . number_format($cost, 2) . '</strong></td>  <td>' . htmlspecialchars($r['staff_name'] ?? '—') . '</td>  <td>' . htmlspecialchars($r['mechanic_name'] ?? '—') . '</td>  <td style="white-space:nowrap;font-size:10px;">' . htmlspecialchars($r['created_at'] ?? '—') . '</td>  </tr>';
}  $summary_rows = '';
foreach ($counts as $c) {  $s = strtolower($c['status'] ?? '');  $sc = $status_colors[$s] ?? '#475569';  $sb = $status_bg[$s]  ?? '#f1f5f9';  $summary_rows .= '<tr>  <td><span style="background:' . $sb . ';color:' . $sc . ';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">' . htmlspecialchars($c['status']) . '</span></td>  <td class="tr">' . (int)$c['cnt'] . '</td>  <td class="tr"><strong>&#8369;' . number_format((float)$c['total_cost'], 2) . '</strong></td>  </tr>';
}  echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Job Orders Report</title>
<style>  *{box-sizing:border-box;margin:0;padding:0}  body{font-family:Arial,sans-serif;font-size:12px;color:#1e293b;padding:24px}  .hdr{margin-bottom:20px;border-bottom:3px solid #002F6C;padding-bottom:12px}  .hdr h1{font-size:20px;color:#002F6C;margin-bottom:4px}  .hdr p{font-size:11px;color:#64748b;margin-top:3px}  .sec{font-size:13px;font-weight:700;color:#002F6C;margin:22px 0 8px;padding:6px 10px;background:#f1f5f9;border-left:4px solid #002F6C}  table{width:100%;border-collapse:collapse;margin-bottom:6px}  th{background:#002F6C;color:#fff;padding:7px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}  td{padding:6px 10px;border-bottom:1px solid #e2e8f0;font-size:11px;vertical-align:middle}  tr:nth-child(even) td{background:#f8fafc}  tfoot td{background:#f1f5f9;border-top:2px solid #002F6C;font-weight:700}  .tr{text-align:right}  .pbtn{margin-bottom:16px}  .pbtn button{background:#002F6C;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer}  @media print{.pbtn{display:none}body{padding:0}}
</style>
</head>
<body>
<div class="pbtn"><button onclick="window.print()">&#128438; Print / Save as PDF</button></div>
<div class="hdr">  <h1>Job Orders Report</h1>  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>  <p><strong>Generated:</strong> ' . date('F j, Y  H:i:s') . '</p>
</div>  <div class="sec">&#128202; Status Summary</div>
<table style="max-width:400px;">  <thead><tr><th>Status</th><th class="tr">Count</th><th class="tr">Total Cost</th></tr></thead>  <tbody>' . $summary_rows . '</tbody>
</table>  <div class="sec">&#128203; Job Orders Detail</div>
' . ($rows ? '
<table>  <thead><tr>  <th>#</th><th>Customer</th><th>Service Type</th><th>Description</th>  <th>Status</th><th class="tr">Cost</th><th>Staff</th><th>Mechanic / Tech</th><th>Date &amp; Time</th>  </tr></thead>  <tbody>' . $tbody . '</tbody>  <tfoot><tr>  <td colspan="5">TOTAL</td>  <td class="tr">&#8369;' . number_format($grand_total, 2) . '</td>  <td colspan="3"></td>  </tr></tfoot>
</table>' : '<p style="color:#94a3b8;font-style:italic;padding:10px 0;">No job orders for this period.</p>') . '
</body>
</html>';
exit;
