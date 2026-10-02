<?php
require_once __DIR__ . '/db_connect.php';

echo "<pre>\n";
$stmt = $pdo->prepare("
    SELECT fp.id, fp.pump_number, fp.fuel_type_id, fp.ugt_no, fi.id AS matched_tank_id, fi.fuel_type AS matched_fuel_type
    FROM fuel_pumps fp
    JOIN fuel_inventory fi ON fi.station_id = fp.station_id 
      AND (
        (fi.fuel_type_id > 0 AND fi.fuel_type_id = fp.fuel_type_id)
        OR (NULLIF(fp.ugt_no,'') IS NOT NULL AND LOWER(REPLACE(fi.ugt_no,'-','')) = LOWER(REPLACE(fp.ugt_no,'-','')))
      )
    WHERE fp.station_id = 1253
    ORDER BY fp.id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " matching pumps to tanks for Station 1253:\n";
foreach ($rows as $r) {
    echo "Pump #{$r['id']} ({$r['pump_number']}) -> Tank ID {$r['matched_tank_id']} ({$r['matched_fuel_type']})\n";
}
echo "</pre>";
