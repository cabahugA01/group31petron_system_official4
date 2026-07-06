<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db_connect.php';

try {  echo "Checking table job_order_service_types...\n";  $stmt = $pdo->query("SHOW TABLES LIKE 'job_order_service_types'");  if ($stmt->rowCount() == 0) {  echo "Table does not exist. Creating table...\n";  } else {  echo "Table exists.\n";  $cols = $pdo->query("SHOW COLUMNS FROM job_order_service_types")->fetchAll(PDO::FETCH_ASSOC);  print_r($cols);  }  // Test the GET query  echo "Running GET query...\n";  $rows = $pdo->query("  SELECT id, service_key, service_name, service_price, min_price, max_price,  price_description, pricing_notes, icon_class, color_class, status  FROM  job_order_service_types  WHERE  active = 1 AND status IN ('approved', 'pending')  ORDER  BY sort_order ASC, service_name ASC  ")->fetchAll(PDO::FETCH_ASSOC);  echo "Fetched " . count($rows) . " rows.\n";  print_r($rows);

} catch (Exception $e) {  echo "ERROR: " . $e->getMessage() . "\n";
}
