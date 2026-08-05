<?php
require_once __DIR__ . '/../public/db_connect.php';

$rows = $pdo->query("SELECT ft.pump_id, fp.pump_number, fi.ugt_no, ft.fuel_type
                     FROM fuel_transactions ft
                     LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
                     LEFT JOIN fuel_inventory fi ON fp.fuel_type_id = fi.fuel_type_id AND ft.station_id = fi.station_id
                     WHERE ft.station_id = 1253
                     GROUP BY ft.pump_id
                     ORDER BY ft.pump_id ASC")->fetchAll(PDO::FETCH_ASSOC);

print_r($rows);
