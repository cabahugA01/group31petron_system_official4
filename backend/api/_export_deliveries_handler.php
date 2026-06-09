<?php
/**
 * Deliveries Export Handler
 * Columns: Delivery ID/Ref, Supplier, Product/Category, Qty Delivered,
 *          Date & Time Received, Encoded By, Status, Remarks
 * Expects: $pdo, $station_id, $date_from, $date_to, $_GET['format']
 */

$fmt = $_GET['format'] ?? 'csv';

// ── fuel_deliveries: received_by + verified_by ────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.delivery_date,
            d.supplier,
            d.invoice_no                        AS reference_no,
            d.fuel_type                         AS product,
            COALESCE(d.delivery_liters, 0)      AS quantity,
            d.status,
            d.notes                             AS remarks,
            d.created_at,
            enc.name                            AS encoded_by,
            ver.name                            AS validated_by
        FROM fuel_deliveries d
        LEFT JOIN users enc ON enc.user_id = d.received_by
        LEFT JOIN users ver ON ver.user_id = d.verified_by
        WHERE d.station_id = ?
          AND DATE(d.delivery_date) BETWEEN ? AND ?
        ORDER BY d.delivery_date DESC
        LIMIT 1000
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── deliveries_oversight: encoded_by + admin_id ───────────────────────────────
try {
    $stmt2 = $pdo->prepare("
        SELECT
            do2.id,
            do2.delivery_date,
            do2.supplier,
            COALESCE(do2.dr_number, do2.delivery_ref, do2.invoice_no) AS reference_no,
            COALESCE(do2.product, do2.product_name)                   AS product,
            COALESCE(do2.quantity, 0)                                 AS quantity,
            do2.status,
            do2.remarks,
            do2.created_at,
            enc2.name                                                  AS encoded_by,
            adm.name                                                   AS validated_by
        FROM deliveries_oversight do2
        LEFT JOIN users enc2 ON enc2.user_id = do2.encoded_by
        LEFT JOIN users adm  ON adm.user_id  = do2.admin_id
        WHERE do2.station_id = ?
          AND DATE(do2.delivery_date) BETWEEN ? AND ?
        ORDER BY do2.delivery_date DESC
        LIMIT 1000
    ");
    $stmt2->execute([$station_id, $date_from, $date_to]);
    $rows = array_merge($rows, $stmt2->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

// Sort merged by delivery_date DESC
usort($rows, fn($a, $b) => strcmp($b['delivery_date'] ?? '', $a['delivery_date'] ?? ''));

$station_name = 'Station #' . $station_id;
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

$filename = 'deliveries_report_' . $date_from . '_to_' . $date_to;

// ════════════════════════════════════════════════════════════════════════════
// CSV / EXCEL
// ════════════════════════════════════════════════════════════════════════════
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Deliveries Report']);
    fputcsv($out, ['Station:', $station_name]);
    fputcsv($out, ['Date Range:', $date_from . ' to ' . $date_to]);
    fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    fputcsv($out, [
        'Delivery ID / Ref', 'Supplier Name', 'Product / Category',
        'Qty Delivered', 'Date & Time Received', 'Encoded By', 'Status', 'Remarks'
    ]);
    foreach ($rows as $r) {
        $ref = $r['reference_no'] ?: ('DEL-' . $r['id']);
        fputcsv($out, [
            $ref,
            $r['supplier'] ?? '—',
            $r['product']  ?? '—',
            number_format((float)$r['quantity'], 2),
            $r['delivery_date'] ?? $r['created_at'] ?? '—',
            $r['encoded_by']   ?? '—',
            $r['status']       ?? '—',
            $r['remarks']      ?? '—',
        ]);
    }
    if ($rows) {
        fputcsv($out, [
            'TOTAL', '', '',
            number_format(array_sum(array_column($rows, 'quantity')), 2),
            '', '', '', ''
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

$status_colors = [
    'pending'                      => '#92400e',
    'pending validation'           => '#92400e',
    'pending manager approval'     => '#92400e',
    'pending manager confirmation' => '#92400e',
    'approved'  => '#166534', 'validated' => '#166534',
    'confirmed' => '#166534', 'verified'  => '#166534',
    'rejected'  => '#991b1b', 'flagged'   => '#991b1b',
];
$status_bg = [
    'pending'                      => '#fef3c7',
    'pending validation'           => '#fef3c7',
    'pending manager approval'     => '#fef3c7',
    'pending manager confirmation' => '#fef3c7',
    'approved'  => '#dcfce7', 'validated' => '#dcfce7',
    'confirmed' => '#dcfce7', 'verified'  => '#dcfce7',
    'rejected'  => '#fee2e2', 'flagged'   => '#fee2e2',
];

$tbody = '';
$total_qty = 0;
foreach ($rows as $r) {
    $s   = strtolower($r['status'] ?? '');
    $sc  = $status_colors[$s] ?? '#475569';
    $sb  = $status_bg[$s]     ?? '#f1f5f9';
    $qty = (float)$r['quantity'];
    $total_qty += $qty;
    $ref = htmlspecialchars($r['reference_no'] ?: ('DEL-' . $r['id']));
    $tbody .= '<tr>
        <td style="font-weight:600;color:#002F6C;">' . $ref . '</td>
        <td><strong>' . htmlspecialchars($r['supplier'] ?? '—') . '</strong></td>
        <td>' . htmlspecialchars($r['product'] ?? '—') . '</td>
        <td class="tr"><strong>' . number_format($qty, 2) . '</strong></td>
        <td style="white-space:nowrap;">' . htmlspecialchars($r['delivery_date'] ?? $r['created_at'] ?? '—') . '</td>
        <td>' . htmlspecialchars($r['encoded_by'] ?? '—') . '</td>
        <td><span style="background:' . $sb . ';color:' . $sc . ';padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600;">' . htmlspecialchars($r['status'] ?? '—') . '</span></td>
        <td style="color:#64748b;font-size:10px;">' . htmlspecialchars(mb_strimwidth($r['remarks'] ?? '—', 0, 60, '…')) . '</td>
    </tr>';
}

echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Deliveries Report</title>
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
  .tr{text-align:right}
  .pbtn{margin-bottom:16px}
  .pbtn button{background:#002F6C;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer}
  @media print{.pbtn{display:none}body{padding:0}}
</style>
</head>
<body>
<div class="pbtn"><button onclick="window.print()">&#128438; Print / Save as PDF</button></div>
<div class="hdr">
  <h1>Deliveries Report</h1>
  <p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>
  <p><strong>Date Range:</strong> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</p>
  <p><strong>Generated:</strong> ' . date('F j, Y  H:i:s') . '</p>
</div>

<div class="sec">&#128666; Deliveries Detail</div>
' . ($rows ? '
<table>
  <thead><tr>
    <th>Delivery ID / Ref</th>
    <th>Supplier Name</th>
    <th>Product / Category</th>
    <th class="tr">Qty Delivered</th>
    <th>Date &amp; Time Received</th>
    <th>Encoded By</th>
    <th>Status</th>
    <th>Remarks</th>
  </tr></thead>
  <tbody>' . $tbody . '</tbody>
  <tfoot><tr>
    <td colspan="3"><strong>TOTAL (' . count($rows) . ' deliveries)</strong></td>
    <td class="tr"><strong>' . number_format($total_qty, 2) . '</strong></td>
    <td colspan="4"></td>
  </tr></tfoot>
</table>' : '<p style="color:#94a3b8;font-style:italic;padding:10px 0;">No delivery records for this period.</p>') . '
</body>
</html>';
exit;
