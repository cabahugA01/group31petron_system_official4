<?php
/**  * One-time migration: Set all service types to active  * Run this once to activate all existing service types  */  require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';  try {  // Update all service types to active  $stmt = $pdo->exec("UPDATE job_order_service_types SET status = 'active', active = 1");  // Get count  $count = $pdo->query("SELECT COUNT(*) FROM job_order_service_types")->fetchColumn();  echo "SUCCESS: All {$count} service type(s) have been set to ACTIVE status.\n";  echo "\nYou can now navigate to the Manager Service Types page to verify.\n";  } catch (Exception $e) {  echo "ERROR: " . $e->getMessage() . "\n";
}
