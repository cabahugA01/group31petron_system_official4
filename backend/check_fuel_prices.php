<?php
/**
 * Debug: show what ft_ids the TABLE renders vs what all_ft_ids_js generates
 * to verify they match (needed for updateFuelCalc to work on page load)
 */
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: text/plain');

// Replicate the tanker_config from staff_transactions_hub.php
$tanker_config = [
    'xcs plus'     => [['name' => 'XCS Plus',     'tankers' => [1, 2, 3, 4]]],
    'turbo diesel' => [['name' => 'Turbo Diesel',  'tankers' => [1, 2]]],
    'xtra unl'     => [
        ['name' => 'XTRA UNL 1', 'tankers' => [1, 2]],
        ['name' => 'XTRA UNL 2', 'tankers' => [3, 4]]
    ],
    'diesel'       => [
        ['name' => 'Diesel 1', 'tankers' => [1, 2, 3, 4]],
        ['name' => 'Diesel 2', 'tankers' => [5, 6]]
    ],
    'kerosene'     => [['name' => 'Kerosene', 'tankers' => [1]]],
];

// Simulate station_id = 1253
$station_id = 1253;
$stmt = $pdo->prepare("SELECT fuel_type, price_per_liter FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
$stmt->execute([$station_id]);
$fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== TABLE RENDERING ft_ids ===\n";
$rendered_config_keys_table = [];
$table_ids = [];
foreach ($fuel_types as $idx => $ft) {
    $ft_lower = strtolower(trim($ft['fuel_type']));
    $config_groups = null;
    $matched_key_table = null;
    foreach ($tanker_config as $key => $groups) {
        if (str_contains($ft_lower, $key)) { $config_groups = $groups; $matched_key_table = $key; break; }
    }
    if ($matched_key_table !== null) {
        if (in_array($matched_key_table, $rendered_config_keys_table)) { continue; }
        $rendered_config_keys_table[] = $matched_key_table;
    }
    if (!$config_groups) $config_groups = [['name' => $ft['fuel_type'], 'tankers' => [1]]];
    foreach ($config_groups as $group) {
        foreach ($group['tankers'] as $tanker_num) {
            $ft_id = 'fuel_' . preg_replace('/[^a-z0-9]/i', '_', $group['name']) . '_' . $idx . '_t' . $tanker_num;
            $table_ids[] = $ft_id;
            echo "  $ft_id\n";
        }
    }
}

echo "\n=== all_ft_ids_js ===\n";
$rendered_for_js = [];
$js_ids = [];
foreach ($fuel_types as $idx_js => $ft_js) {
    $ft_lower_js = strtolower(trim($ft_js['fuel_type']));
    $cfg_js = null; $key_js = null;
    foreach ($tanker_config as $k => $g) {
        if (str_contains($ft_lower_js, $k)) { $cfg_js = $g; $key_js = $k; break; }
    }
    if ($key_js !== null) {
        if (in_array($key_js, $rendered_for_js)) continue;
        $rendered_for_js[] = $key_js;
    }
    if (!$cfg_js) $cfg_js = [['name' => $ft_js['fuel_type'], 'tankers' => [1]]];
    foreach ($cfg_js as $grp_js) {
        foreach ($grp_js['tankers'] as $tn_js) {
            $id = 'fuel_' . preg_replace('/[^a-z0-9]/i', '_', $grp_js['name']) . '_' . $idx_js . '_t' . $tn_js;
            $js_ids[] = $id;
            echo "  $id\n";
        }
    }
}

echo "\n=== MATCH CHECK ===\n";
$all_match = true;
foreach ($table_ids as $i => $tid) {
    $jid = $js_ids[$i] ?? 'MISSING';
    $match = ($tid === $jid) ? 'OK' : 'MISMATCH!';
    if ($tid !== $jid) $all_match = false;
    echo "  Table[$i]: $tid | JS[$i]: $jid | $match\n";
}
if (count($js_ids) !== count($table_ids)) {
    echo "  COUNT MISMATCH: table=".count($table_ids)." js=".count($js_ids)."\n";
    $all_match = false;
}
echo $all_match ? "\nAll IDs match OK!\n" : "\nMISMATCH DETECTED!\n";
