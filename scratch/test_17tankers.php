<?php
require_once __DIR__ . '/../public/db_connect.php';
$station_id = 1253;

$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6],
    ['fuel_type'=>'Kerosene',    'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel','label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel','label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>1],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>3],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>4],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>1],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>2],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>3],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>4],
];

$pm_inv_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, latest_calibration, last_updated, fuel_type_id FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
        $pm_inv_lookup[strtolower(trim($row['fuel_type']))] = $row;
} catch (Exception $e) { echo "inv error: ".$e->getMessage()."\n"; }

$pump_master_fuel_types = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key  = strtolower(trim($tc['fuel_type']));
    $inv     = $pm_inv_lookup[$ft_key] ?? null;
    $cal_value = $inv ? (float)$inv['latest_calibration'] : 0;
    $pump_master_fuel_types[] = [
        'label'     => $tc['label'],
        'tank'      => $tc['tank'],
        'fuel_type' => $tc['fuel_type'],
        'cal_value' => $cal_value,
        'status'    => $cal_value > 0 ? 'Pending' : 'No Reading',
    ];
}

echo "Total tankers: " . count($pump_master_fuel_types) . "\n\n";
printf("%-20s %-22s %-12s %s\n", "LABEL", "FUEL TYPE", "CAL VALUE", "STATUS");
echo str_repeat("-", 70) . "\n";
foreach ($pump_master_fuel_types as $r)
    printf("%-20s %-22s %-12s %s\n", $r['label'], $r['fuel_type'], $r['cal_value'], $r['status']);
