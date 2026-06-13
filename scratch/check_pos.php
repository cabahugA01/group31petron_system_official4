<?php
require_once __DIR__ . '/../public/db_connect.php';
$station_id = 1253;

echo "=== purchase_orders for station_id = $station_id ===\n";
$stmt = $pdo->prepare("SELECT id, po_number, total_amount, type, status, supplier_id, supplier_name FROM purchase_orders WHERE station_id = ?");
$stmt->execute([$station_id]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
