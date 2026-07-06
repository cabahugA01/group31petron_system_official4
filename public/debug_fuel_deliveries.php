<?php
// Debug fuel deliveries data
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();  $me = current_user();
$station_id = user_station_id();  echo "<h1>Debug: Fuel Deliveries History Data</h1>";
echo "<p><strong>Current User:</strong> " . htmlspecialchars($me['name'] ?? $me['username']) . " (ID: {$me['id']})</p>";
echo "<p><strong>Station ID:</strong> {$station_id}</p>";
echo "<hr>";  // Check if fuel_deliveries table exists
try {  $check = $pdo->query("SHOW TABLES LIKE 'fuel_deliveries'")->fetch();  if (!$check) {  echo "<p style='color:red;'>Table 'fuel_deliveries' does NOT exist!</p>";  exit;  }  echo "<p style='color:green;'>Table 'fuel_deliveries' exists</p>";
} catch (Exception $e) {  echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";  exit;
}  // Count total records
try {  $total = $pdo->query("SELECT COUNT(*) FROM fuel_deliveries")->fetchColumn();  echo "<p><strong>Total records in fuel_deliveries:</strong> {$total}</p>";
} catch (Exception $e) {  echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}  // Count records at this station
try {  $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ?");  $stmt->execute([$station_id]);  $station_count = $stmt->fetchColumn();  echo "<p><strong>Records at your station:</strong> {$station_count}</p>";
} catch (Exception $e) {  echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}  // Count diesel records at this station
try {  $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ? AND fuel_type LIKE '%Diesel%'");  $stmt->execute([$station_id]);  $diesel_count = $stmt->fetchColumn();  echo "<p><strong>Diesel records at your station:</strong> {$diesel_count}</p>";
} catch (Exception $e) {  echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}  echo "<hr>";
echo "<h2>All Fuel Deliveries at Your Station</h2>";  try {  $stmt = $pdo->prepare("  SELECT fd.*,  COALESCE(u.full_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown') AS encoded_by_name,  COALESCE(um.full_name, CONCAT(um.first_name, ' ', um.last_name), um.username) AS manager_name  FROM fuel_deliveries fd  LEFT JOIN users u ON fd.received_by = u.id  LEFT JOIN users um ON fd.validated_by = um.id  WHERE fd.station_id = ?  ORDER BY fd.delivery_date DESC, fd.created_at DESC  ");  $stmt->execute([$station_id]);  $records = $stmt->fetchAll(PDO::FETCH_ASSOC);  if (empty($records)) {  echo "<p style='color:orange;'>No fuel delivery records found at your station.</p>";  echo "<p><strong>Action needed:</strong> Record a fuel delivery via 'Record Fuel Delivery' menu.</p>";  } else {  echo "<p style='color:green;'>Found " . count($records) . " record(s)</p>";  echo "<table border='1' cellpadding='8' style='border-collapse:collapse; width:100%;'>";  echo "<tr style='background:#f0f0f0;'>";  echo "<th>ID</th><th>Batch ID</th><th>Fuel Type</th><th>Supplier</th><th>Invoice</th>";  echo "<th>Liters</th><th>Tank</th><th>Tanker</th><th>Date</th><th>Encoded By</th>";  echo "<th>Status</th><th>Manager</th><th>Notes</th><th>Created</th>";  echo "</tr>";  foreach ($records as $r) {  $highlight = (stripos($r['fuel_type'], 'diesel') !== false) ? "background:#ffffcc;" : "";  echo "<tr style='{$highlight}'>";  echo "<td>{$r['id']}</td>";  echo "<td style='font-family:monospace; color:#002F70;'><strong>" . htmlspecialchars($r['batch_id']) . "</strong></td>";  echo "<td><strong>" . htmlspecialchars($r['fuel_type']) . "</strong></td>";  echo "<td>" . htmlspecialchars($r['supplier']) . "</td>";  echo "<td style='font-family:monospace;'>" . htmlspecialchars($r['invoice_no']) . "</td>";  echo "<td><strong>" . number_format($r['delivery_liters'], 2) . " L</strong></td>";  echo "<td>" . htmlspecialchars($r['tank_assigned']) . "</td>";  echo "<td style='font-family:monospace;'>" . htmlspecialchars($r['tanker_number']) . "</td>";  echo "<td>" . htmlspecialchars($r['delivery_date']) . "</td>";  echo "<td>" . htmlspecialchars($r['encoded_by_name'] ?? 'Unknown') . "</td>";  echo "<td><strong>" . htmlspecialchars($r['status']) . "</strong></td>";  echo "<td>" . htmlspecialchars($r['manager_name'] ?? '—') . "</td>";  echo "<td>" . htmlspecialchars(mb_strimwidth($r['validation_notes'] ?? $r['notes'] ?? '—', 0, 30, '…')) . "</td>";  echo "<td>" . htmlspecialchars($r['created_at']) . "</td>";  echo "</tr>";  }  echo "</table>";  echo "<p style='margin-top:20px;'><em>Note: Yellow highlighted rows are Diesel deliveries</em></p>";  }
} catch (Exception $e) {  echo "<p style='color:red;'>Error fetching records: " . $e->getMessage() . "</p>";
}  echo "<hr>";
echo "<h2>Query Used in History Page</h2>";
echo "<pre style='background:#f5f5f5; padding:12px; border-radius:6px;'>";
echo "SELECT fd.*, u.name AS encoded_by_name, um.name AS manager_name
FROM fuel_deliveries fd
LEFT JOIN users u ON fd.received_by = u.id
LEFT JOIN users um ON fd.validated_by = um.id
WHERE fd.station_id = {$station_id}
ORDER BY  FIELD(fd.status, 'Rejected', 'Pending Manager Validation', 'Validated', 'Approved'),  fd.delivery_date DESC,  fd.created_at DESC";
echo "</pre>";  echo "<hr>";
echo "<p><a href='staff_fuel_delivery_status.php'>← Back to Fuel Deliveries History</a></p>";
echo "<p><a href='staff_fuel_deliveries.php'>→ Record New Fuel Delivery</a></p>";
?>
