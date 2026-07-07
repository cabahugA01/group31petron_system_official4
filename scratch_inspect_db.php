<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

function dumpTable($pdo, $table) {  echo "--- $table ---\n";  try {  $q = $pdo->query("DESCRIBE `$table`");  while ($row = $q->fetch(PDO::FETCH_ASSOC)) {  echo "{$row['Field']} - {$row['Type']}\n";  }  } catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";  }
}

dumpTable($pdo, 'customers');
dumpTable($pdo, 'fuel_transactions');
dumpTable($pdo, 'merchandise_transactions');
dumpTable($pdo, 'job_orders');
