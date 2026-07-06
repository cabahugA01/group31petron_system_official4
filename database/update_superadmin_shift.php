<?php
/**  * Update SuperAdmin Shift Assignment  */  require_once __DIR__ . '/../public/db_connect.php';  header('Content-Type: text/plain');  echo "Updating SuperAdmin shift assignment...\n";  try {  $stmt = $pdo->exec("UPDATE `users` SET `assigned_shift` = 'All Shifts' WHERE `role` = 'superadmin'");  echo " Updated $stmt superadmin account(s) to 'All Shifts'\n";
} catch (PDOException $e) {  echo "Error: " . $e->getMessage() . "\n";
}
?>
