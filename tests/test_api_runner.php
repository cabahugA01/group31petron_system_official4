<?php
require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/plain');

function fetch_pumps_test($pdo, $station_id, $fuel) {
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
    try {
        $sql = "SELECT id, pump_number, pump_name, nozzle_number, status 
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
        $pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $pumps = []; }

    return $pumps;
}

$stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = 1253 ORDER BY id");
$stmt->execute();
$fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== FUEL PRODUCTS FOR VAMENTA (1253) ===\n";
foreach ($fuels as $f) {
    echo "ID: {$f['id']} | Fuel: {$f['fuel_type']} | UGT: {$f['ugt_no']} | ft_id: {$f['fuel_type_id']}\n";
    $pumps = fetch_pumps_test($pdo, 1253, $f);
    echo "  Pumps returned (" . count($pumps) . "):\n";
    foreach ($pumps as $p) {
        echo "    - ID: {$p['id']} | Pump#: {$p['pump_number']}\n";
    }
}
