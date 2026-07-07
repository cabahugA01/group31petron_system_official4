<?php
/**
 * STAFF CUSTOMER REPORT EXPORT - FILTERED CUSTOMERS
 * Exports the filtered customer list matching the current filter state.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'superadmin', 'developer'])) {  die('Unauthorized access');
}

if (!$station_id) {  die('Error: You are not assigned to a station.');
}

// 1. Get filter inputs
$search  = trim($_GET['search'] ?? '');
$type  = trim($_GET['type'] ?? '');
$status  = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo  = trim($_GET['date_to'] ?? '');
$format  = strtolower(trim($_GET['format'] ?? 'excel'));

// 2. Build where clause
$where = ['c.station_id = ?'];
$params = [$station_id];

if ($search !== '') {  $where[] = "(c.customer_id LIKE ? OR CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) LIKE ? OR c.contact_number LIKE ?)";  $s = "%$search%";  array_push($params, $s, $s, $s);
}
if ($type !== '') {  $where[] = "c.customer_type = ?";  $params[] = $type;
}
if ($status !== '') {  $where[] = "c.status = ?";  $params[] = $status;
}
if ($dateFrom !== '') {  $where[] = "DATE(c.registered_at) >= ?";  $params[] = $dateFrom;
}
if ($dateTo !== '') {  $where[] = "DATE(c.registered_at) <= ?";  $params[] = $dateTo;
}

$wc = implode(' AND ', $where);

// 3. Fetch customers with transaction stats
$stmt = $pdo->prepare("  SELECT  c.id,  COALESCE(c.customer_id, CAST(c.id AS CHAR)) AS customer_id,  COALESCE(c.first_name,'') AS first_name,  COALESCE(c.middle_name,'') AS middle_name,  COALESCE(c.last_name,'') AS last_name,  COALESCE(c.contact_number,'') AS contact_number,  COALESCE(c.customer_type,'walk-in') AS customer_type,  COALESCE(c.status,'active') AS status,  COALESCE(c.registered_at, c.created_at) AS registered_at,  COALESCE(txn_stats.total_transactions, 0) AS total_transactions,  txn_stats.last_transaction  FROM customers c  LEFT JOIN (  SELECT  customer_id,  COUNT(*) AS total_transactions,  MAX(txn_date) AS last_transaction  FROM (  SELECT customer_id, COALESCE(transaction_date, created_at) AS txn_date FROM merchandise_transactions WHERE station_id = ?  UNION ALL  SELECT customer_id, created_at AS txn_date FROM job_orders WHERE station_id = ?  UNION ALL  SELECT customer_id, COALESCE(transaction_date, created_at) AS txn_date FROM fuel_transactions WHERE station_id = ?  ) all_txns  GROUP BY customer_id  ) txn_stats ON txn_stats.customer_id = c.id  WHERE $wc  ORDER BY c.registered_at DESC, c.id DESC
");

// Add station_id three times at the beginning for the subquery
array_unshift($params, $station_id, $station_id, $station_id);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Get Station Info
$station_name = 'Petron Station';
$station_location = '';
try {  $st = $pdo->prepare("SELECT name, location, address FROM stations WHERE id = ? LIMIT 1");  $st->execute([$station_id]);  $station = $st->fetch(PDO::FETCH_ASSOC);  if ($station) {  $station_name = $station['name'] ?: $station_name;  $station_location = $station['address'] ?: ($station['location'] ?: '');  }
} catch (Exception $e) {}

$generated_by = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($generated_by === '') $generated_by = $me['username'] ?? 'Staff';

// 5. Handle CSV Export
if ($format === 'csv') {  $filename = 'Customers_Export_' . date('Ymd_His') . '.csv';  header('Content-Type: text/csv; charset=utf-8');  header('Content-Disposition: attachment; filename="' . $filename . '"');  $output = fopen('php://output', 'w');  fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8  fputcsv($output, ['Customer ID', 'Customer Name', 'Contact Number', 'Customer Type', 'Total Transactions', 'Last Transaction', 'Status', 'Date Registered']);  foreach ($customers as $c) {  $fullName = trim($c['first_name'] . ' ' . $c['middle_name'] . ' ' . $c['last_name']);  fputcsv($output, [  $c['customer_id'],  $fullName,  $c['contact_number'],  ucfirst($c['customer_type']),  $c['total_transactions'],  $c['last_transaction'] ? date('Y-m-d H:i', strtotime($c['last_transaction'])) : 'Never',  ucfirst($c['status']),  date('Y-m-d H:i', strtotime($c['registered_at'])),  ]);  }  fclose($output);  exit;
}

// 6. Handle Excel Export
if ($format === 'excel') {  $filename = 'Customers_Export_' . date('Ymd_His') . '.xls';  header('Content-Type: application/vnd.ms-excel; charset=utf-8');  header('Content-Disposition: attachment; filename="' . $filename . '"');  echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';  echo '<head><meta charset="UTF-8"><style>';  echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';  echo 'th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-size: 12px; }';  echo 'th { background-color: #002F70; color: #ffffff; font-weight: bold; }';  echo 'h1 { color: #002F70; font-size: 18px; margin: 5px 0; }';  echo '</style></head><body>';  echo '<h1>PETRON CUSTOMER LIST REPORT</h1>';  echo '<p><strong>Station Name:</strong> ' . htmlspecialchars($station_name) . '<br>';  echo '<strong>Branch/Address:</strong> ' . htmlspecialchars($station_location) . '<br>';  echo '<strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '<br>';  echo '<strong>Exported By:</strong> ' . htmlspecialchars($generated_by) . '</p>';  echo '<table><thead><tr>';  echo '<th>Customer ID</th><th>Customer Name</th><th>Contact Number</th><th>Customer Type</th><th>Total Transactions</th><th>Last Transaction</th><th>Status</th><th>Date Registered</th>';  echo '</tr></thead><tbody>';  foreach ($customers as $c) {  $fullName = trim($c['first_name'] . ' ' . $c['middle_name'] . ' ' . $c['last_name']);  echo '<tr>';  echo '<td>' . htmlspecialchars($c['customer_id']) . '</td>';  echo '<td>' . htmlspecialchars($fullName) . '</td>';  echo '<td>' . htmlspecialchars($c['contact_number']) . '</td>';  echo '<td>' . htmlspecialchars(ucfirst($c['customer_type'])) . '</td>';  echo '<td>' . htmlspecialchars($c['total_transactions']) . '</td>';  echo '<td>' . ($c['last_transaction'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($c['last_transaction']))) : 'Never') . '</td>';  echo '<td>' . htmlspecialchars(ucfirst($c['status'])) . '</td>';  echo '<td>' . htmlspecialchars(date('Y-m-d H:i', strtotime($c['registered_at']))) . '</td>';  echo '</tr>';  }  echo '</tbody></table></body></html>';  exit;
}

// 7. Handle PDF Export
if ($format === 'pdf') {  header('Content-Type: text/html; charset=utf-8');  ?>  <!DOCTYPE html>  <html>  <head>  <meta charset="UTF-8">  <title>Customer List PDF - <?= date('Y-m-d') ?></title>  <style>  body { font-family: Arial, sans-serif; color: #333; margin: 20px; font-size: 11px; line-height: 1.4; }  .header { border-bottom: 2px solid #002F70; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }  .header h1 { margin: 0; color: #002F70; font-size: 18px; text-transform: uppercase; }  .station-info { text-align: right; color: #555; }  table { width: 100%; border-collapse: collapse; margin-top: 10px; }  th { background: #002F70; color: white; padding: 6px 8px; font-size: 10px; text-align: left; text-transform: uppercase; border: 1px solid #cbd5e1; }  td { padding: 6px 8px; border: 1px solid #e2e8f0; }  tr:nth-child(even) { background: #f8fafc; }  .footer { margin-top: 30px; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }  .no-print { display: flex; justify-content: center; gap: 8px; margin-bottom: 20px; }  .no-print button { padding: 8px 16px; font-size: 12px; cursor: pointer; font-weight: bold; background: #002F70; color: white; border: none; border-radius: 4px; }  .no-print button.close { background: #64748b; }  @media print { .no-print { display: none; } }  </style>  </head>  <body>  <div class="no-print">  <button onclick="window.print()">Print PDF</button>  <button class="close" onclick="window.close()">Close Window</button>  </div>  <div class="header">  <div>  <h1>Petron Customer Directory</h1>  <div style="margin-top: 4px;"><strong>Generated By:</strong> <?= htmlspecialchars($generated_by) ?></div>  <div><strong>Export Date:</strong> <?= date('F d, Y h:i A') ?></div>  </div>  <div class="station-info">  <strong><?= htmlspecialchars($station_name) ?></strong><br>  <?= htmlspecialchars($station_location) ?>  </div>  </div>  <p><strong>Total Records:</strong> <?= count($customers) ?> customers match the selected filter criteria.</p>  <table>  <thead>  <tr>  <th>Customer ID</th>  <th>Customer Name</th>  <th>Contact Number</th>  <th>Type</th>  <th>Transactions</th>  <th>Last Transaction</th>  <th>Status</th>  <th>Date Registered</th>  </tr>  </thead>  <tbody>  <?php if (empty($customers)): ?>  <tr><td colspan="8" style="text-align: center; color: #888;">No customers found matching criteria.</td></tr>  <?php else: ?>  <?php foreach ($customers as $c):  $fullName = trim($c['first_name'] . ' ' . $c['middle_name'] . ' ' . $c['last_name']);  ?>  <tr>  <td><strong><?= htmlspecialchars($c['customer_id']) ?></strong></td>  <td><?= htmlspecialchars($fullName) ?></td>  <td><?= htmlspecialchars($c['contact_number']) ?></td>  <td><?= ucfirst(htmlspecialchars($c['customer_type'])) ?></td>  <td style="text-align: right;"><?= $c['total_transactions'] ?></td>  <td><?= $c['last_transaction'] ? date('M d, Y H:i', strtotime($c['last_transaction'])) : 'Never' ?></td>  <td><?= ucfirst(htmlspecialchars($c['status'])) ?></td>  <td><?= date('M d, Y', strtotime($c['registered_at'])) ?></td>  </tr>  <?php endforeach; ?>  <?php endif; ?>  </tbody>  </table>  <div class="footer">  System Generated Report - Petron Management System - Confidential  </div>  <script>  window.onload = function() {  // Auto trigger print dialogue for user convenience  // window.print();  }  </script>  </body>  </html>  <?php  exit;
}
?>
