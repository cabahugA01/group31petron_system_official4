<?php
// Fix Titles for the 4 pages matching user exactly
$f1 = 'public/manager_fuel_transactions.php';
$c1 = file_get_contents($f1);
$c1 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Fuel Transactions</h1><div class="sub">Review pump readings ug reconciliation nga gi-encode sa staff. Manager action: Validate / Approve / Adjust.</div>', $c1);
file_put_contents($f1, $c1);

$f2 = 'public/manager_fuel_deliveries.php';
$c2 = file_get_contents($f2);
$c2 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Fuel Deliveries</h1><div class="sub">Review supplier DR nga gi-encode sa staff. Manager action: Approve / Reject / Adjust.</div>', $c2);
file_put_contents($f2, $c2);

$f3 = 'public/manager_fuel_adjustments.php';
$c3 = file_get_contents($f3);
$c3 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Adjustments</h1><div class="sub">Encode corrections sa tank levels, pump readings, or delivery entries. Manager action: add reason + timestamp.</div>', $c3);
file_put_contents($f3, $c3);

$f4 = 'public/manager_fuel_pump_master.php';
$c4 = file_get_contents($f4);
$c4 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', '<h1 class="h1">Pump Master</h1><div class="sub">Manage pump list ug calibration records. Manager action: add/edit pump details, assign calibration schedules.</div>', $c4);
file_put_contents($f4, $c4);

echo "Titles customized perfectly!\n";
