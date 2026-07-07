<?php
require_once __DIR__ . '/../public/db_connect.php';  echo "=== Service Types Verification ===\n\n";  $count = $pdo->query('SELECT COUNT(*) FROM job_order_service_types')->fetchColumn();
echo "Total service types: $count\n\n";  $categories = $pdo->query('  SELECT category, COUNT(*) as count  FROM job_order_service_types  GROUP BY category  ORDER BY category
')->fetchAll(PDO::FETCH_ASSOC);  echo "By Category:\n";
foreach($categories as $c) {  echo "  {$c['category']}: {$c['count']} services\n";
}  echo "\n Service types successfully loaded!\n";
?>
