<?php
// Fix default tab selection for Deliveries
$f2 = 'public/manager_fuel_deliveries.php';
$c2 = file_get_contents($f2);
$c2 = str_replace("setTimeout(() => showSectionOnly('fuel-transactions'), 100);", "setTimeout(() => showSectionOnly('fuel-deliveries'), 100);", $c2);
file_put_contents($f2, $c2);

// Fix default tab selection for Adjustments
$f3 = 'public/manager_fuel_adjustments.php';
$c3 = file_get_contents($f3);
$c3 = str_replace("setTimeout(() => showSectionOnly('fuel-transactions'), 100);", "setTimeout(() => showSectionOnly('adjustments'), 100);", $c3);
file_put_contents($f3, $c3);

// Fix default tab selection for Pump Master
$f4 = 'public/manager_fuel_pump_master.php';
$c4 = file_get_contents($f4);
$c4 = str_replace("setTimeout(() => showSectionOnly('fuel-transactions'), 100);", "setTimeout(() => showSectionOnly('pump-master'), 100);", $c4);
file_put_contents($f4, $c4);

echo "Visibility fixed by targeting the correct default section for each file!\n";
