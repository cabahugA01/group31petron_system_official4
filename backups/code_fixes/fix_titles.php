<?php
// Fix Titles for the 4 pages
$f1 = 'public/manager_fuel_transactions.php';
$c1 = file_get_contents($f1);
$c1 = preg_replace('/<h1 class="h1">Manager Fuel Management<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Fuel Transactions</h1><div class="sub">Review pump readings and reconciliation encoded by staff. Validate, approve, or adjust entries.</div>', $c1);
file_put_contents($f1, $c1);

$f2 = 'public/manager_fuel_deliveries.php';
$c2 = file_get_contents($f2);
$c2 = preg_replace('/<h1 class="h1">Manager Fuel Management<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Fuel Deliveries</h1><div class="sub">Review supplier DR encoded by staff. Approve, reject, or adjust incoming fuel stock.</div>', $c2);
file_put_contents($f2, $c2);

$f3 = 'public/manager_fuel_adjustments.php';
$c3 = file_get_contents($f3);
$c3 = preg_replace('/<h1 class="h1">Manager Fuel Management<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Fuel Adjustments</h1><div class="sub">Encode corrections to tank levels, pump readings, or delivery entries. All actions are strictly audited.</div>', $c3);
file_put_contents($f3, $c3);

$f4 = 'public/manager_fuel_pump_master.php';
$c4 = file_get_contents($f4);
$c4 = preg_replace('/<h1 class="h1">Manager Fuel Management<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Pump Master</h1><div class="sub">Manage pump list and calibration records. Add or edit pump details and assign calibration schedules.</div>', $c4);
file_put_contents($f4, $c4);

echo "Titles customized!\n";
