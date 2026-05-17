<?php
$f1 = 'public/manager_fuel_transactions.php';
$c1 = file_get_contents($f1);
$sub1 = '<h1 class="h1">Fuel Transactions</h1><div class="sub" style="margin-top:10px; line-height:1.6; font-size:0.9rem;"><strong>Function:</strong> Review pump readings ug reconciliation nga gi-encode sa staff.<br><strong>Manager Action:</strong> Validate / Approve / Adjust.<br><strong>Purpose:</strong> Ensure sakto ang liters sold vs revenue before mo-reflect sa reports.</div>';
$c1 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', $sub1, $c1);
file_put_contents($f1, $c1);

$f2 = 'public/manager_fuel_deliveries.php';
$c2 = file_get_contents($f2);
$sub2 = '<h1 class="h1">Fuel Deliveries</h1><div class="sub" style="margin-top:10px; line-height:1.6; font-size:0.9rem;"><strong>Function:</strong> Review supplier Delivery Receipts nga gi-encode sa staff.<br><strong>Manager Action:</strong> Approve / Reject / Adjust.<br><strong>Purpose:</strong> Confirm sakto ang stock inflow ug supplier compliance.</div>';
$c2 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', $sub2, $c2);
file_put_contents($f2, $c2);

$f3 = 'public/manager_fuel_adjustments.php';
$c3 = file_get_contents($f3);
$sub3 = '<h1 class="h1">Adjustments</h1><div class="sub" style="margin-top:10px; line-height:1.6; font-size:0.9rem;"><strong>Function:</strong> Encode corrections sa tank levels, pump readings, or delivery entries.<br><strong>Manager Action:</strong> Add reason + timestamp (system logs Old vs New values).<br><strong>Purpose:</strong> Transparency ug accountability sa corrections.</div>';
$c3 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', $sub3, $c3);
file_put_contents($f3, $c3);

$f4 = 'public/manager_fuel_pump_master.php';
$c4 = file_get_contents($f4);
$sub4 = '<h1 class="h1">Pump Master</h1><div class="sub" style="margin-top:10px; line-height:1.6; font-size:0.9rem;"><strong>Function:</strong> Manage pump list ug calibration records.<br><strong>Manager Action:</strong> Add/edit pump details, assign calibration schedules.<br><strong>Purpose:</strong> Maintain pump accuracy ug compliance sa weekly calibration.</div>';
$c4 = preg_replace('/<h1 class="h1">.*?<\/h1>\s*<div class="sub">.*?<\/div>/is', $sub4, $c4);
file_put_contents($f4, $c4);

echo "Expanded titles customized perfectly!\n";
