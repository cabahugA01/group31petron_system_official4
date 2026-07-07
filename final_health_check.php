<?php
require_once __DIR__ . '/public/db_connect.php';

$station_id = 1;
$date = date('Y-m-d');
$errors = [];
$ok = [];

function test_query($pdo, $sql, $params, $label, &$ok, &$errors) {  try {  $stmt = $pdo->prepare($sql);  $stmt->execute($params);  $result = $stmt->fetchAll(PDO::FETCH_ASSOC);  $ok[] = "$label (rows: " . count($result) . ")";  } catch (Exception $e) {  $errors[] = "$label: " . $e->getMessage();  }
}

// Manager dashboard queries
test_query($pdo, "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?", [$station_id, $date], "fuel_transactions count", $ok, $errors);
test_query($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?", [$station_id, $date], "merchandise_transactions count", $ok, $errors);
test_query($pdo, "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ?", [$station_id, $date], "job_orders count", $ok, $errors);
test_query($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = ?", [$station_id, $date], "fuel revenue", $ok, $errors);
test_query($pdo, "SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) = ?", [$station_id, $date], "merch revenue", $ok, $errors);
test_query($pdo, "SELECT * FROM users WHERE id = 1 LIMIT 1", [], "user fetch by ID", $ok, $errors);
test_query($pdo, "SELECT * FROM stations WHERE id = 1 LIMIT 1", [], "station fetch", $ok, $errors);
test_query($pdo, "SELECT * FROM pending_price_approvals WHERE status = 'pending' LIMIT 5", [], "pending_price_approvals", $ok, $errors);
test_query($pdo, "SELECT * FROM voided_transactions WHERE station_id = ? LIMIT 5", [$station_id], "voided_transactions", $ok, $errors);
test_query($pdo, "SELECT * FROM transaction_adjustments WHERE station_id = ? LIMIT 5", [$station_id], "transaction_adjustments", $ok, $errors);
test_query($pdo, "SELECT * FROM customers LIMIT 5", [], "customers", $ok, $errors);
test_query($pdo, "SELECT * FROM fuel_types LIMIT 5", [], "fuel_types", $ok, $errors);
test_query($pdo, "SELECT * FROM inventory_products WHERE station_id = 1 LIMIT 5", [], "inventory_products by station", $ok, $errors);
test_query($pdo, "SELECT * FROM purchase_orders WHERE station_id = ? ORDER BY created_at DESC LIMIT 5", [$station_id], "purchase_orders", $ok, $errors);
test_query($pdo, "SELECT * FROM stock_requests WHERE station_id = ? ORDER BY created_at DESC LIMIT 5", [$station_id], "stock_requests", $ok, $errors);

echo "=== QUERY HEALTH CHECK ===\n\n";
echo " PASSED (" . count($ok) . "):\n";
foreach ($ok as $r) echo "  $r\n";

if ($errors) {  echo "\n FAILED (" . count($errors) . "):\n";  foreach ($errors as $e) echo "  $e\n";
} else {  echo "\n All queries passed! System is ready.\n";
}
