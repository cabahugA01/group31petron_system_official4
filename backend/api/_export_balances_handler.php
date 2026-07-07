<?php
/**  * Customer Balances Export Handler  * Columns: Customer Name/ID, Outstanding Balance, Credit Limit, Usage %, Due Date, Status, Remarks  * Expects: $pdo, $station_id, $_GET['format']  */  $fmt = $_GET['format'] ?? 'csv';  // ── Detect schema ─────────────────────────────────────────────────────────────
$mt_cust_col = null;
try { $pdo->query("SELECT credit_customer_id FROM merchandise_transactions LIMIT 0"); $mt_cust_col = 'credit_customer_id'; }
catch (Exception $e) { try { $pdo->query("SELECT customer_id FROM merchandise_transactions LIMIT 0"); } catch (Exception $e2) { $mt_cust_col = null; } }  $bal_col = 'balance';
try { $pdo->query("SELECT credit_balance FROM customers LIMIT 0"); $bal_col = 'credit_balance'; } catch (Exception $e) {}  $has_ar = false;
try { $pdo->query("SELECT 1 FROM accounts_receivable LIMIT 0"); $has_ar = true; } catch (Exception $e) {}  $ar_join = $has_ar  ? "LEFT JOIN (SELECT customer_id, MIN(due_date) AS due_date, status FROM accounts_receivable WHERE station_id = ? AND status IN ('Pending','Active') GROUP BY customer_id) ar ON ar.customer_id = c.id"  : "";
$ar_due  = $has_ar ? "ar.due_date" : "NULL";
$ar_status = $has_ar ? "COALESCE(ar.status, 'Active')" : "'Active'";
$params_bal = $has_ar ? [$station_id, $station_id] : [$station_id];  $stmt = $pdo->prepare("  SELECT  c.id,  c.name  AS customer_name,  COALESCE(c.{$bal_col}, 0)  AS outstanding_balance,  COALESCE(c.credit_limit, 0)  AS credit_limit,  CASE  WHEN COALESCE(c.credit_limit, 0) > 0  THEN ROUND((COALESCE(c.{$bal_col}, 0) / c.credit_limit) * 100, 1)  ELSE 0  END  AS usage_pct,  {$ar_due}  AS due_date,  CASE  WHEN COALESCE(c.{$bal_col}, 0) = 0  THEN 'Settled'  WHEN COALESCE(c.{$bal_col}, 0) > COALESCE(c.credit_limit, 0) AND COALESCE(c.credit_limit, 0) > 0 THEN 'Over Limit'  WHEN {$ar_due} IS NOT NULL AND {$ar_due} < CURDATE()  THEN 'Overdue'  ELSE 'Active'  END  AS payment_status,  COALESCE(c.notes, c.mgr_notes, '')  AS remarks  FROM customers c  $ar_join  WHERE c.station_id = ?  ORDER BY outstanding_balance DESC
");
$stmt->execute($params_bal);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);  $station_name = 'Station #' . $station_id;
try {  $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");  $sn->execute([$station_id]);  $station_name = $sn->fetchColumn() ?: $station_name;
} catch (Exception $e) {}  $filename = 'customer_balances_' . date('Y-m-d');  // ════════════════════════════════════════════════════════════════════════════
// CSV / EXCEL
// ════════════════════════════════════════════════════════════════════════════
if ($fmt === 'csv') {  header('Content-Type: text/csv; charset=UTF-8');  header('Content-Disposition: attachment; filename="' . $filename . '.csv"');  header('Cache-Control: no-cache, no-store, must-revalidate');  $out = fopen('php://output', 'w');  fputs($out, "\xEF\xBB\xBF");  fputcsv($out, ['Customer Balances Report']);  fputcsv($out, ['Station:', $station_name]);  fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);  fputcsv($out, []);  fputcsv($out, ['Customer ID', 'Customer Name', 'Outstanding Balance (PHP)', 'Credit Limit (PHP)', 'Usage %', 'Due Date', 'Status', 'Remarks']);  $tot_bal = 0; $tot_lim = 0;  foreach ($rows as $r) {  $bal = (float)$r['outstanding_balance'];  $lim = (float)$r['credit_limit'];  $tot_bal += $bal; $tot_lim += $lim;  $remarks = $r['remarks'] ?: ($bal > $lim && $lim > 0 ? 'Exceeded credit limit' : ($r['payment_status'] === 'Overdue' ? 'Payment past due date' : '—'));  fputcsv($out, [  '#' . $r['id'],  $r['customer_name'],  number_format($bal, 2),  $lim > 0 ? number_format($lim, 2) : 'No limit',  $r['usage_pct'] . '%',  $r['due_date'] ?? '—',  $r['payment_status'],  $remarks,  ]);  }  if ($rows) {  fputcsv($out, ['', 'TOTAL', number_format($tot_bal, 2), number_format($tot_lim, 2), '', '', '', '']);  }  fclose($out);  exit;
}  // ════════════════════════════════════════════════════════════════════════════
// PDF
// ════════════════════════════════════════════════════════════════════════════
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');  $status_styles = [  'settled'  => ['bg'=>'#d1fae5','color'=>'#065f46'],  'overdue'  => ['bg'=>'#fee2e2','color'=>'#991b1b'],  'over limit' => ['bg'=>'#fef3c7','color'=>'#92400e'],  'active'  => ['bg'=>'#dbeafe','color'=>'#1e40af'],
];  $tbody = '';
$tot_bal = 0; $tot_lim = 0;
foreach ($rows as $r) {  $bal  = (float)$r['outstanding_balance'];  $lim  = (float)$r['credit_limit'];  $pct  = (float)$r['usage_pct'];  $status = $r['payment_status'] ?? 'Active';  $tot_bal += $bal; $tot_lim += $lim;  $ss = $status_styles[strtolower($status)] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];  $bar_color = $pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#d97706' : '#002F6C');  $bar_w = min($pct, 100);  $remarks = $r['remarks'] ?: ($bal > $lim && $lim > 0 ? 'Exceeded credit limit' : ($status === 'Overdue' ? 'Payment past due date' : '—'));  $tbody .= '<tr>  <td>  <strong>' . htmlspecialchars($r['customer_name'] ?? '—') . '</strong>  <div style="font-size:9px;color:#94a3b8;">#' . (int)$r['id'] . '</div>  </td>  <td class="tr" style="color:' . ($bal>0?'#dc2626':'#16a34a') . ';font-weight:700;">&#8369;' . number_format($bal, 2) . '</td>  <td class="tr">' . ($lim > 0 ? '&#8369;' . number_format($lim, 2) : '<span style="color:#94a3b8;">No limit</span>') . '</td>  <td>' . ($lim > 0  ? '<div style="display:flex;align-items:center;gap:5px;">  <div style="flex:1;background:#e2e8f0;border-radius:3px;height:6px;overflow:hidden;">  <div style="width:' . $bar_w . '%;height:100%;background:' . $bar_color . ';border-radius:3px;"></div>  </div>  <span style="font-size:9px;font-weight:600;color:' . $bar_color . '">' . $pct . '%</span>  </div>'  : '<span style="color:#94a3b8;">—</span>') . '</td>  <td style="' . ($status==='Overdue'?'color:#dc2626;font-weight:600;':'') . '">' . htmlspecialchars($r['due_date'] ?? '—') . '</td>  <td><span style="background:' . $ss['bg'] . ';color:' . $ss['color'] . ';padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600;">' . htmlspecialchars($status) . '</span></td>  <td style="font-size:10px;color:#64748b;">' . htmlspecialchars(mb_strimwidth($remarks, 0, 50, '…')) . '</td>  </tr>';
}  echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Balances Report</title>
<style>  *{box-sizing:border-box;margin:0;padding:0}  body{font-family:Arial,sans-serif;font-size:12px;color:#1e293b;padding:24px}  .hdr{margin-bottom:20px;border-bottom:3px solid #002F6C;padding-bottom:12px}  .hdr h1{font-size:20px;color:#002F6C;margin-bottom:4px}  .hdr p{font-size:11px;color:#64748b;margin-top:3px}  .sec{font-size:13px;font-weight:700;color:#002F6C;margin:22px 0 8px;padding:6px 10px;background:#f1f5f9;border-left:4px solid #002F6C}  table{width:100%;border-collapse:collapse;margin-bottom:6px}  th{background:#002F6C;color:#fff;padding:7px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}  td{padding:6px 10px;border-bottom:1px solid #e2e8f0;font-size:11px;vertical-align:middle}  tr:nth-child(even) td{background:#f8fafc}  tfoot td{background:#f1f5f9;border-top:2px solid #002F6C;font-weight:700}  .tr{text-align:right}  .pbtn{margin-bottom:16px}  .pbtn button{background:#002F6C;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer}  @media print{.pbtn{display:none}body{padding:0}}
</style>
</head>
<body>
<div class="pbtn"><button onclick="window.print()">&#128438; Print / Save as PDF</button></div>
<div class="hdr">  <h1>Customer Balances Report</h1>  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>  <p><strong>Generated:</strong> ' . date('F j, Y  H:i:s') . '</p>
</div>  <div class="sec">&#128179; Customer Balances</div>
' . ($rows ? '
<table>  <thead><tr>  <th>Customer Name / ID</th>  <th class="tr">Outstanding Balance</th>  <th class="tr">Credit Limit</th>  <th>Usage</th>  <th>Due Date</th>  <th>Status</th>  <th>Remarks</th>  </tr></thead>  <tbody>' . $tbody . '</tbody>  <tfoot><tr>  <td><strong>TOTAL (' . count($rows) . ' customers)</strong></td>  <td class="tr"><strong>&#8369;' . number_format($tot_bal, 2) . '</strong></td>  <td class="tr"><strong>&#8369;' . number_format($tot_lim, 2) . '</strong></td>  <td colspan="4"></td>  </tr></tfoot>
</table>' : '<p style="color:#94a3b8;font-style:italic;padding:10px 0;">No customer balance records found.</p>') . '
</body>
</html>';
exit;
