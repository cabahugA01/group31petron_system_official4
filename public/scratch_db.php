<?php
require_once __DIR__ . '/db_connect.php';
$station_id = 1253;

$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5,  'capacity'=>50000],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6,  'capacity'=>50000],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>7,  'capacity'=>20000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>8,  'capacity'=>45000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>9,  'capacity'=>45000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>10, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>11, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>12, 'capacity'=>20000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>13, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>14, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>15, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>16, 'capacity'=>20000],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>17, 'capacity'=>20000],
];

$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }
} catch (Exception $e) {}

$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }
} catch (Exception $e) {}

$adj_lookup = [];
try {
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }
} catch (Exception $e) {}

foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key   = strtolower(trim($tc['fuel_type']));
    if ($ft_key === 'xtra unl') {
        if (strpos(strtolower($tc['label']), 'xtra unl 1') !== false) {
            $ft_key = 'xtra unl 1';
        } elseif (strpos(strtolower($tc['label']), 'xtra unl 2') !== false) {
            $ft_key = 'xtra unl 2';
        }
    }
    
    $tank_key = strtolower(trim($tc['tank']));
    $inv      = $fi_lookup[$ft_key] ?? null;

    $capacity  = (float)$tc['capacity'];
    $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;

    $same_type_count = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key) {
        $k = strtolower(trim($t['fuel_type']));
        if ($k === 'xtra unl') {
            if (strpos(strtolower($t['label']), 'xtra unl 1') !== false) {
                $k = 'xtra unl 1';
            } elseif (strpos(strtolower($t['label']), 'xtra unl 2') !== false) {
                $k = 'xtra unl 2';
            }
        }
        return $k === $ft_key;
    }));

    $purchases = $del_lookup[$tank_key] ?? 0;

    $sales_total = $sales_lookup[$ft_key] ?? 0;
    $adj_total   = $adj_lookup[$ft_key] ?? 0;
    $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
    $calibration = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

    $beginning = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;
    $total_available = $beginning + $purchases;
    $ending_system   = max(0, $total_available - $sales - $calibration);

    $fill_pct = $capacity > 0 ? ($ending_system / $capacity) * 100 : 0;
    if ($ending_system <= 0) {
        $status = 'Out of Stock';
    } elseif ($fill_pct <= 10) {
        $status = 'Critical';
    } elseif ($fill_pct <= 25) {
        $status = 'Low';
    } else {
        $status = 'Normal';
    }

    echo "{$tc['label']} ({$ft_key}): System ending volume = $ending_system, Status = $status, Capacity = $capacity, Cur Level = $cur_level\n";
}
