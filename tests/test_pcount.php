<?php
require_once __DIR__ . '/../public/db_connect.php';

$target_sid = 1253;

$s = $pdo->prepare("SELECT id, fuel_type, ugt_no, capacity, current_stock, price_per_liter FROM fuel_inventory WHERE station_id = ?");
$s->execute([$target_sid]);
$fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);

// Exactly as lines 660-682 in admin_set_prices.php
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
echo "=== ADMIN_SET_PRICES PUMP COUNTS FOR 1253 ===\n";
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
    
    echo "ID: $r_id | Fuel: {$row['fuel_type']} | UGT: {$row['ugt_no']} | computed pump_count: $p_count\n";
}

echo "\nWhat is in pumps_by_ugt:\n";
print_r(array_map('count', $pumps_by_ugt));

echo "\nWhat is in pumps_by_tank:\n";
print_r(array_map('count', $pumps_by_tank));

echo "</pre>";
