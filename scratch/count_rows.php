<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['fuel_transaction_audit', 'fuel_audit_trail', 'validation_actions_log', 'fuel_adjustments', 'fuel_transactions', 'fuel_deliveries'];
foreach ($tables as $t) {  try {  $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();  echo "$t: $count rows\n";  if ($count > 0) {  $sample = $pdo->query("SELECT * FROM $t LIMIT 1")->fetch(PDO::FETCH_ASSOC);  echo "  Sample: " . json_encode($sample) . "\n";  }  } catch (Exception $e) {  echo "$t error: " . $e->getMessage() . "\n";  }
}
