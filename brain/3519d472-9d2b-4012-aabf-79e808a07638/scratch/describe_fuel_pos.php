<?php
require_once 'c:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
$stmt = $pdo->query("DESCRIBE fuel_purchase_orders");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
