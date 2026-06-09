<?php
/**
 * Staff Performance Export Handler
 * Columns: Staff ID, Staff Name, Role, Transactions Encoded,
 *          Job Orders Encoded, Deliveries Encoded, Shifts Logged, Total Hours
 * + Attendance/Shift Logs section
 * Expects: $pdo, $station_id, $date_from, $date_to, $_GET['format']
 */

$fmt = $_GET['format'] ?? 'csv';

// ── Detect schema variations ──────────────────────────────────────────────────
$jo_user_id = false;
try { $pdo->query("SELECT user_id FROM job_orders LIMIT 0"); $jo_user_id = true; } catch (Exception $e) {}
$jo_created_expr = $jo_user_id ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.created_by';

$has_del = false;
try { $pdo->query("SELECT 1 FROM deliveries_oversight LIMIT 0"); $has_del = true; } catch (Exception $e) {}
$del_join  = $has_del ? "LEFT JOIN deliveries_oversight del ON del.encoded_by = u.id AND del.station_id = ? AND DATE(del.delivery_date) BETWEEN ? AND ?" : "";
$del_count = $has_del ? "COUNT(DISTINCT del.id) AS del_count," : "0 AS del_count,";

$params = [
    $station_id, $date_from, $date_to,
    $station_id, $date_from, $date_to,
    $station_id, $date_from, $date_to,
    $station_id, $date_from, $date_to,
];
if ($has_del) { $params[] = $station_id; $params[] = $date_from; $params[] = $date_to; }
$params[] = $station_id;

// ── Performance data ──────────────────────────────────────────────────────────
$perf_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            u.id, u.name, u.role,
            COUNT(DISTINCT ft.transaction_id)                                                     AS fuel_txn_count,
            COUNT(DISTINCT mt.id)                                                                 AS merch_txn_count,
            COUNT(DISTINCT jo.id)                                                                 AS jo_count,
            $del_count
            COUNT(DISTINCT ls.id)                                                                 AS shift_count,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW()))), 0) AS total_minutes
        FROM users u
        LEFT JOIN fuel_transactions ft
            ON ft.staff_id = u.id AND ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
        LEFT JOIN merchandise_transactions mt
            ON mt.staff_id = u.id AND mt.station_id = ? AND DATE(mt.transaction_date) BETWEEN ? AND ?
        LEFT JOIN job_orders jo
            ON ($jo_created_expr = u.id) AND jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
        LEFT JOIN labor_sessions ls
            ON ls.user_id = u.id AND ls.station_id = ? AND DATE(ls.start_time) BETWEEN ? AND ?
        $del_join
        WHERE u.station_id = ?
          AND LOWER(u.role) IN ('staff','cashier','pump_attendant','mechanic')
          AND u.status = 'Active'
        GROUP BY u.id, u.name, u.role
        ORDER BY (COUNT(DISTINCT ft.transaction_id) + COUNT(DISTINCT mt.id) + COUNT(DISTINCT jo.id)) DESC, u.name ASC
    ");
    $stmt->execute($params);
    $perf_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Attendance data ───────────────────────────────────────────────────────────
$att_rows = [];
try {
    $stmt2 = $pdo->prepare("
        SELECT
            u.name,
            ls.shift_name,
            DATE(ls.start_time)  AS work_date,
            TIME(ls.start_time)  AS clock_in,
            TIME(ls.end_time)    AS clock_out,
            CASE
                WHEN ls.end_time IS NOT NULL
                THEN ROUND(TIMESTAMPDIFF(MINUTE, ls.start_time, ls.end_time) / 60.0, 2)
                ELSE NULL
            END AS hours_worked
        FROM labor_sessions ls
        JOIN users u ON u.user_id = ls.user_id
        WHERE ls.station_id = ?
          AND DATE(ls.start_time) BETWEEN ? AND ?
        ORDER BY ls.start_time DESC
        LIMIT 1000
    ");
    $stmt2->execute([$station_id, $date_from, $date_to]);
    $att_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$station_name = 'Station #' . $station_id;
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

$filename = 'staff_performance_' . $date_from . '_to_' . $date_to;

function fmtHoursExport($mins) {
    if (!$mins) return '0h 00m';
    $h = floor($mins/60); $m = $mins%60;
    return $h . 'h ' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm';
}

// ════════════════════════════════════════════════════════════════════════════
// CSV / EXCEL
// ════════════════════════════════════════════════════════════════════════════
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Staff Performance Report']);
    fputcsv($out, ['Station:', $station_name]);
    fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
    fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    // Performance Summary
    fputcsv($out, ['PERFORMANCE SUMMARY']);
    fputcsv($out, ['Staff ID', 'Staff Name', 'Role', 'Transactions Encoded', 'Job Orders Encoded', 'Deliveries Encoded', 'Shifts Logged', 'Total Hours']);
    $tot_txn = $tot_jo = $tot_del = $tot_shift = $tot_mins = 0;
    foreach ($perf_rows as $r) {
        $txn = (int)$r['fuel_txn_count'] + (int)$r['merch_txn_count'];
        $tot_txn   += $txn;
        $tot_jo    += (int)$r['jo_count'];
        $tot_del   += (int)($r['del_count']??0);
        $tot_shift += (int)$r['shift_count'];
        $tot_mins  += (int)$r['total_minutes'];
        fputcsv($out, [
            $r['id'],
            $r['name'],
            $r['role'],
            $txn,
            $r['jo_count'],
            $r['del_count'] ?? 0,
            $r['shift_count'],
            fmtHoursExport((int)$r['total_minutes']),
        ]);
    }
    if ($perf_rows) {
        fputcsv($out, ['', 'TOTAL', '', $tot_txn, $tot_jo, $tot_del, $tot_shift, fmtHoursExport($tot_mins)]);
    }
    fputcsv($out, []);

    // Attendance Logs
    fputcsv($out, ['ATTENDANCE / SHIFT LOGS']);
    fputcsv($out, ['Staff Name', 'Shift', 'Date', 'Time In', 'Time Out', 'Hours Worked']);
    foreach ($att_rows as $r) {
        fputcsv($out, [
            $r['name'],
            $r['shift_name'] ?? '—',
            $r['work_date'],
            $r['clock_in'],
            $r['clock_out'] ?? 'Active',
            $r['hours_worked'] !== null ? number_format((float)$r['hours_worked'], 2) . ' hrs' : 'On shift',
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

$perf_tbody = '';
$tot_txn = $tot_jo = $tot_del = $tot_shift = $tot_mins = 0;
foreach ($perf_rows as $r) {
    $txn = (int)$r['fuel_txn_count'] + (int)$r['merch_txn_count'];
    $tot_txn   += $txn;
    $tot_jo    += (int)$r['jo_count'];
    $tot_del   += (int)($r['del_count']??0);
    $tot_shift += (int)$r['shift_count'];
    $tot_mins  += (int)$r['total_minutes'];
    $perf_tbody .= '<tr>
        <td style="color:#94a3b8;font-size:10px;">#' . (int)$r['id'] . '</td>
        <td><strong>' . htmlspecialchars($r['name'] ?? '—') . '</strong></td>
        <td><span style="font-size:10px;background:#f1f5f9;padding:1px 6px;border-radius:4px;">' . htmlspecialchars($r['role'] ?? '—') . '</span></td>
        <td class="tc"><strong>' . $txn . '</strong><div style="font-size:9px;color:#94a3b8;">' . (int)$r['fuel_txn_count'] . ' fuel · ' . (int)$r['merch_txn_count'] . ' merch</div></td>
        <td class="tc"><strong>' . (int)$r['jo_count'] . '</strong></td>
        <td class="tc"><strong>' . (int)($r['del_count']??0) . '</strong></td>
        <td class="tc"><strong>' . (int)$r['shift_count'] . '</strong></td>
        <td class="tr">' . fmtHoursExport((int)$r['total_minutes']) . '</td>
    </tr>';
}

$att_tbody = '';
foreach ($att_rows as $r) {
    $att_tbody .= '<tr>
        <td><strong>' . htmlspecialchars($r['name'] ?? '—') . '</strong></td>
        <td>' . htmlspecialchars($r['shift_name'] ?? '—') . '</td>
        <td>' . htmlspecialchars($r['work_date'] ?? '—') . '</td>
        <td>' . htmlspecialchars($r['clock_in'] ?? '—') . '</td>
        <td>' . htmlspecialchars($r['clock_out'] ?? 'Active') . '</td>
        <td class="tr">' . ($r['hours_worked'] !== null ? number_format((float)$r['hours_worked'], 2) . ' hrs' : '<span style="color:#16a34a;">On shift</span>') . '</td>
    </tr>';
}

echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Staff Performance Report</title>
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
  tfoot td{background:#f1f5f9;border-top:2px solid #002F6C;font-weight:700}
  .tr{text-align:right} .tc{text-align:center}
  .pbtn{margin-bottom:16px}
  .pbtn button{background:#002F6C;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer}
  @media print{.pbtn{display:none}body{padding:0}}
</style>
</head>
<body>
<div class="pbtn"><button onclick="window.print()">&#128438; Print / Save as PDF</button></div>
<div class="hdr">
  <h1>Staff Performance Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
  <p><strong>Generated:</strong> ' . date('F j, Y  H:i:s') . '</p>
</div>

<div class="sec">&#128202; Performance Summary</div>
' . ($perf_rows ? '
<table>
  <thead><tr>
    <th>Staff ID</th><th>Staff Name</th><th>Role</th>
    <th class="tc">Transactions<br>Encoded</th>
    <th class="tc">Job Orders<br>Encoded</th>
    <th class="tc">Deliveries<br>Encoded</th>
    <th class="tc">Shifts<br>Logged</th>
    <th class="tr">Total Hours</th>
  </tr></thead>
  <tbody>' . $perf_tbody . '</tbody>
  <tfoot><tr>
    <td colspan="3"><strong>TOTAL (' . count($perf_rows) . ' staff)</strong></td>
    <td class="tc"><strong>' . $tot_txn . '</strong></td>
    <td class="tc"><strong>' . $tot_jo . '</strong></td>
    <td class="tc"><strong>' . $tot_del . '</strong></td>
    <td class="tc"><strong>' . $tot_shift . '</strong></td>
    <td class="tr"><strong>' . fmtHoursExport($tot_mins) . '</strong></td>
  </tr></tfoot>
</table>' : '<p style="color:#94a3b8;font-style:italic;padding:10px 0;">No performance data for this period.</p>') . '

<div class="sec">&#128336; Attendance / Shift Logs</div>
' . ($att_rows ? '
<table>
  <thead><tr>
    <th>Staff Name</th><th>Shift</th><th>Date</th>
    <th>Time In</th><th>Time Out</th><th class="tr">Hours Worked</th>
  </tr></thead>
  <tbody>' . $att_tbody . '</tbody>
</table>' : '<p style="color:#94a3b8;font-style:italic;padding:10px 0;">No attendance records for this period.</p>') . '
</body>
</html>';
exit;
