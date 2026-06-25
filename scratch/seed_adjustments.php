<?php
require_once __DIR__ . '/../public/db_connect.php';
$pdo->exec("DELETE FROM fuel_adjustments WHERE station_id = 1253");
$rows = [
    ['2026-06-20','Diesel','Calibration',-50.00,10250.00,10200.00,'Weekly pump calibration for Diesel Tank','Pending',null,null,2],
    ['2026-06-21','XCS Plus','Theft/Loss',-120.50,8420.50,8300.00,'Stock discrepancy after shift handover','Pending',null,null,2],
    ['2026-06-22','Turbo Diesel','Spillage',-15.00,4520.00,4505.00,'Fuel spillage during tanker discharge','Approved','2026-06-22 14:00:00',3,2],
    ['2026-06-22','XTRA UNL','Transfer',500.00,15000.00,15500.00,'Transfer from Tank 14 to Tank 15','Rejected','2026-06-22 16:00:00',3,2],
    ['2026-06-23','Kerosene','Calibration',25.00,2450.00,2475.00,'Calibration correction by supervisor','Approved','2026-06-23 10:00:00',3,2],
    ['2026-06-24','Diesel','Transfer',-200.00,9800.00,9600.00,'Emergency transfer to secondary tank','Pending',null,null,2],
    ['2026-06-24','XCS Plus','Spillage',-30.00,6200.00,6170.00,'Minor nozzle leak during fill-up','Approved','2026-06-24 11:30:00',4,2],
    ['2026-06-25','XTRA UNL','Theft/Loss',-80.00,12000.00,11920.00,'Unaccounted volume post-audit','Rejected','2026-06-25 08:00:00',4,2],
];
$s = $pdo->prepare("INSERT INTO fuel_adjustments (station_id,adjustment_date,fuel_type,adjustment_type,liters,previous_value,new_value,reason,status,approved_at,approved_by,user_id) VALUES (1253,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($rows as $r) {
    $s->execute([...$r]);
}
echo "Seeded ".count($rows)." adjustments for Station 1253\n";
