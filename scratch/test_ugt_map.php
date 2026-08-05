<?php
require_once __DIR__ . '/../public/db_connect.php';

$st_params = ['station_id' => 1253];
$st_clause = " AND ft.station_id = :station_id ";

$all_pumps_stmt = $pdo->prepare(
    "SELECT DISTINCT ft.pump_id
     FROM fuel_transactions ft
     WHERE 1=1 {$st_clause}
     ORDER BY ft.pump_id ASC"
);
$all_pumps_stmt->execute($st_params);
$all_pump_ids = $all_pumps_stmt->fetchAll(PDO::FETCH_COLUMN);

$ugt_map = [];
$pump_list = [];
foreach ($all_pump_ids as $idx => $pid) {
    $code = sprintf('UGT-%02d', $idx + 1);
    $ugt_map[$pid] = $code;
    $pump_list[] = ['pump_id' => $pid, 'label' => $code];
}

print_r($pump_list);
