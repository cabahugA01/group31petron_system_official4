<?php
require_once __DIR__ . '/db_connect.php';

$target_sid = 1253;

$s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ?");
$s->execute([$target_sid]);
$fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);

$pumps_by_tank = [];
$pumps_by_ugt = [];
$p_stmt = $pdo->prepare("SELECT id, tank_id, ugt_no, fuel_type_id FROM fuel_pumps WHERE station_id = ?");
$p_stmt->execute([$target_sid]);
foreach ($p_stmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    if (!empty($pr['tank_id'])) {
        $pumps_by_tank[(int)$pr['tank_id']][] = $pr;
    }
    $u_clean = strtolower(trim($pr['ugt_no'] ?? ''));
    if ($u_clean) {
        $pumps_by_ugt[$u_clean][] = $pr;
        $u_num = preg_replace('/[^0-9]/', '', $u_clean);
        if ($u_num) {
            $pumps_by_ugt['ugt_' . (int)$u_num][] = $pr;
            $pumps_by_ugt['ugt #' . (int)$u_num][] = $pr;
            $pumps_by_ugt['ugt-' . (int)$u_num][] = $pr;
            $pumps_by_ugt['ugt-' . sprintf('%02d', (int)$u_num)][] = $pr;
        }
    }
}

echo "<pre>\n";
echo "PUMP COUNTS CURRENTLY CALCULATED:\n";
foreach ($fi_raw as $row) {
    $r_id = (int)$row['id'];
    $ugt_val = trim($row['ugt_no'] ?? '');
    $ugt_key = strtolower($ugt_val);
    $p_count = 0;
    if (isset($pumps_by_tank[$r_id])) {
        $p_count = count($pumps_by_tank[$r_id]);
    } elseif ($ugt_key && isset($pumps_by_ugt[$ugt_key])) {
        $p_count = count($pumps_by_ugt[$ugt_key]);
    }
    echo "Tank ID {$r_id} | {$row['fuel_type']} | {$ugt_val} => {$p_count} pumps\n";
}
echo "</pre>";
