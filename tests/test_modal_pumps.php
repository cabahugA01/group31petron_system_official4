<?php
require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/plain');

$stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = 1253 ORDER BY CAST(REGEXP_REPLACE(ugt_no, '[^0-9]', '') AS UNSIGNED) ASC");
$stmt->execute();
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

function test_fetch_pumps($pdo, $station_id, $fuel) {
    $pumps = [];
    $ft_id = (int)($fuel['fuel_type_id'] ?? 0);
    $raw_ugt = trim($fuel['ugt_no'] ?? '');
    $ugt_num = (int)preg_replace('/[^0-9]/', '', $raw_ugt);
    $fname = strtolower(trim($fuel['fuel_type'] ?? ''));

    $ugt_list = array_filter(array_unique([
        $raw_ugt,
        $ugt_num ? sprintf('UGT-%02d', $ugt_num) : '',
        $ugt_num ? sprintf('UGT #%d', $ugt_num) : '',
        $ugt_num ? sprintf('UGT-%d', $ugt_num) : '',
        $ugt_num ? sprintf('UGT %d', $ugt_num) : '',
        $ugt_num ? (string)$ugt_num : '',
    ]));

    $prefix = '';
    if (strpos($fname, 'turbo') !== false) {
        $prefix = 'TURBO DIESEL - %';
    } elseif (strpos($fname, 'diesel 1') !== false || $ugt_num === 1) {
        $prefix = 'DIESEL 1 - %';
    } elseif (strpos($fname, 'diesel 2') !== false || $ugt_num === 2) {
        $prefix = 'DIESEL 2 - %';
    } elseif (strpos($fname, 'xcs') !== false || $ugt_num === 4) {
        $prefix = 'XCS PLUS - %';
    } elseif (strpos($fname, 'xtra unl 1') !== false || (strpos($fname, 'xtra') !== false && strpos($fname, '1') !== false) || $ugt_num === 5) {
        $prefix = 'XTRA UNL 1 - %';
    } elseif (strpos($fname, 'xtra unl 2') !== false || (strpos($fname, 'xtra') !== false && strpos($fname, '2') !== false) || $ugt_num === 6) {
        $prefix = 'XTRA UNL 2 - %';
    } elseif (strpos($fname, 'kero') !== false || $ugt_num === 7) {
        $prefix = 'KEROSENE - %';
    }

    $inv_id = (int)($fuel['id'] ?? 0);
    $sql = "SELECT id, pump_number, pump_name, nozzle_number, status, ugt_no, fuel_type_id, tank_id 
            FROM fuel_pumps 
            WHERE station_id = ? 
              AND (
                (? > 0 AND tank_id = ?)
                OR (? > 0 AND fuel_type_id = ?)
                " . (!empty($ugt_list) ? " OR ugt_no IN (" . implode(',', array_fill(0, count($ugt_list), '?')) . ")" : "") . "
                " . ($prefix !== '' ? " OR UPPER(pump_number) LIKE ?" : "") . "
              )
            ORDER BY id ASC";
    $params = [$station_id, $inv_id, $inv_id, $ft_id, $ft_id];
    if (!empty($ugt_list)) {
        $params = array_merge($params, array_values($ugt_list));
    }
    if ($prefix !== '') {
        $params[] = $prefix;
    }
    $p_stmt = $pdo->prepare($sql);
    $p_stmt->execute($params);
    return $p_stmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($all as $fuel) {
    $pumps = test_fetch_pumps($pdo, 1253, $fuel);
    echo "=== Fuel ID {$fuel['id']} ({$fuel['fuel_type']}, {$fuel['ugt_no']}) -> " . count($pumps) . " pumps found ===\n";
    foreach ($pumps as $p) {
        echo "  - Pump ID {$p['id']}: {$p['pump_number']} (UGT: " . ($p['ugt_no'] ?? '') . ", ft_id: " . ($p['fuel_type_id'] ?? '') . ")\n";
    }
}
