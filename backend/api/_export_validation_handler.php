<?php
/**
 * Validation Logs Export Handler
 * Columns: Date & Time, Manager Name, Role, Action, Details, IP Address
 * Expects: $pdo, $station_id, $date_from, $date_to, $_GET['format']
 */

$fmt = $_GET['format'] ?? 'csv';

$stmt = $pdo->prepare("
    SELECT
        al.id,
        al.created_at,
        al.action,
        al.details,
        al.ip_address,
        u.name  AS manager_name,
        u.role  AS manager_role
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE u.station_id = ?
        AND LOWER(u.role) IN ('manager','admin','superadmin','super admin')
        AND DATE(al.created_at) BETWEEN ? AND ?
    ORDER BY al.created_at DESC
    LIMIT 1000
");
$stmt->execute([$station_id, $date_from, $date_to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$station_name = 'Station #' . $station_id;
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

$filename = 'validation_logs_' . $date_from . '_to_' . $date_to;

// ════════════════════════════════════════════════════════════════════════════
// CSV / EXCEL
// ════════════════════════════════════════════════════════════════════════════
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Validation Logs Report']);
    fputcsv($out, ['Station:', $station_name]);
    fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
    fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Total Records:', count($rows)]);
    fputcsv($out, []);

    fputcsv($out, ['Date & Time', 'Manager Name', 'Role', 'Action', 'Details', 'IP Address']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['created_at'],
            $r['manager_name'] ?? 'System',
            $r['manager_role'] ?? '—',
            $r['action']       ?? '—',
            $r['details']      ?? '—',
            $r['ip_address']   ?? '—',
        ]);
    }
    fclose($out);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// PDF
// ════════════════════════════════════════════════════════════════════════════
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Action color coding
function actionStyle($action) {
    $a = strtolower($action ?? '');
    if (strpos($a,'approve')!==false || strpos($a,'validat')!==false || strpos($a,'confirm')!==false || strpos($a,'verify')!==false)
        return ['bg'=>'#dcfce7','color'=>'#166534'];
    if (strpos($a,'reject')!==false || strpos($a,'deny')!==false || strpos($a,'flag')!==false)
        return ['bg'=>'#fee2e2','color'=>'#991b1b'];
    if (strpos($a,'adjust')!==false || strpos($a,'edit')!==false || strpos($a,'update')!==false || strpos($a,'print')!==false)
        return ['bg'=>'#dbeafe','color'=>'#1e40af'];
    if (strpos($a,'rbac')!==false || strpos($a,'access')!==false || strpos($a,'denied')!==false)
        return ['bg'=>'#fef3c7','color'=>'#92400e'];
    return ['bg'=>'#f3f4f6','color'=>'#374151'];
}

// Action summary counts
$action_counts = [];
foreach ($rows as $r) {
    $a = $r['action'] ?? 'Unknown';
    $action_counts[$a] = ($action_counts[$a] ?? 0) + 1;
}
arsort($action_counts);

$summary_rows = '';
foreach ($action_counts as $action => $cnt) {
    $s = actionStyle($action);
    $summary_rows .= '<tr>
        <td><span style="background:' . $s['bg'] . ';color:' . $s['color'] . ';padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;">' . htmlspecialchars($action) . '</span></td>
        <td class="tr">' . $cnt . '</td>
    </tr>';
}

$tbody = '';
foreach ($rows as $r) {
    $s = actionStyle($r['action'] ?? '');
    $tbody .= '<tr>
        <td style="white-space:nowrap;font-size:10px;color:#64748b;">' . htmlspecialchars($r['created_at'] ?? '—') . '</td>
        <td><strong>' . htmlspecialchars($r['manager_name'] ?? 'System') . '</strong></td>
        <td><span style="font-size:10px;background:#f1f5f9;padding:1px 6px;border-radius:4px;">' . htmlspecialchars($r['manager_role'] ?? '—') . '</span></td>
        <td><span style="background:' . $s['bg'] . ';color:' . $s['color'] . ';padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;">' . htmlspecialchars($r['action'] ?? '—') . '</span></td>
        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;font-size:10px;">' . htmlspecialchars(mb_strimwidth($r['details'] ?? '—', 0, 80, '…')) . '</td>
        <td style="font-size:10px;color:#94a3b8;white-space:nowrap;">' . htmlspecialchars($r['ip_address'] ?? '—') . '</td>
    </tr>';
}

echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Validation Logs Report</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,sans-serif;font-size:12px;color:#1e293b;padding:24px}
  .hdr{margin-bottom:20px;border-bottom:3px solid #002F6C;padding-bottom:12px}
  .hdr h1{font-size:20px;color:#002F6C;margin-bottom:4px}
  .hdr p{font-size:11px;color:#64748b;margin-top:3px}
  .sec{font-size:13px;font-weight:700;color:#002F6C;margin:22px 0 8px;padding:6px 10px;background:#f1f5f9;border-left:4px solid #002F6C}
  table{width:100%;border-collapse:collapse;margin-bottom:6px}
  th{background:#002F6C;color:#fff;padding:7px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
  td{padding:6px 10px;border-bottom:1px solid #e2e8f0;font-size:11px;vertical-align:middle}
  tr:nth-child(even) td{background:#f8fafc}
  .tr{text-align:right}
  .pbtn{margin-bottom:16px}
  .pbtn button{background:#002F6C;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer}
  @media print{.pbtn{display:none}body{padding:0}}
</style>
</head>
<body>
<div class="pbtn"><button onclick="window.print()">&#128438; Print / Save as PDF</button></div>
<div class="hdr">
  <h1>Validation Logs Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
  <p><strong>Generated:</strong> ' . date('F j, Y  H:i:s') . '</p>
  <p><strong>Total Records:</strong> ' . count($rows) . '</p>
</div>

<div class="sec">&#128202; Action Summary</div>
<table style="max-width:360px;">
  <thead><tr><th>Action</th><th class="tr">Count</th></tr></thead>
  <tbody>' . $summary_rows . '</tbody>
</table>

<div class="sec">&#128203; Validation Log Detail</div>
' . ($rows ? '
<table>
  <thead><tr>
    <th>Date &amp; Time</th>
    <th>Manager Name</th>
    <th>Role</th>
    <th>Action</th>
    <th>Details</th>
    <th>IP Address</th>
  </tr></thead>
  <tbody>' . $tbody . '</tbody>
</table>' : '<p style="color:#94a3b8;font-style:italic;padding:10px 0;">No validation logs for this period.</p>') . '
</body>
</html>';
exit;
