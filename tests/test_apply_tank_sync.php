<?php
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: text/plain');

$stmt = $pdo->prepare("
    UPDATE fuel_pumps fp
    JOIN fuel_inventory fi ON fi.station_id = fp.station_id 
      AND (
        (fi.fuel_type_id > 0 AND fi.fuel_type_id = fp.fuel_type_id)
        OR (NULLIF(fp.ugt_no,'') IS NOT NULL AND LOWER(REPLACE(fi.ugt_no,'-','')) = LOWER(REPLACE(fp.ugt_no,'-','')))
      )
    SET fp.tank_id = fi.id
    WHERE fp.station_id = 1253
");
$stmt->execute();
echo "Rows updated: " . $stmt->rowCount() . "\n";

$p = $pdo->query("SELECT id, pump_number, tank_id, ugt_no, fuel_type_id FROM fuel_pumps WHERE station_id = 1253 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
print_r($p);
