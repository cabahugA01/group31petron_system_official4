<?php
$source = file_get_contents('public/manager_fuel_management_complete.php');

$parts = explode('<!-- ----------------------------------------------------------', $source);
// $parts[0] = header up to the first divider
// $parts[1] = SECTION 1
// $parts[2] = SECTION 2
// $parts[3] = SECTION 3
// $parts[4] = TAB 3 (Adjustments)
// $parts[5] = TAB 4 (Reconciliation)
// $parts[6] = TAB 5 (Variance)
// $parts[7] = TAB 6 (Shift)
// $parts[8] = TAB: WEEKLY
// $parts[9] = TAB 7 (Pump)
// $parts[10] = MODALS

// Re-attach the divider to each part
foreach($parts as $k => $v) {
    if ($k > 0) {
        $parts[$k] = '<!-- ----------------------------------------------------------' . $v;
    }
}

$header = $parts[0];
$sec1 = $parts[1];
$sec2 = $parts[2];
$sec3 = $parts[3];
$tab3 = $parts[4];
$tab4 = $parts[5];
$tab5 = $parts[6];
$tab6 = $parts[7];
$tabWeekly = $parts[8];
$tab7 = $parts[9];
$modals = $parts[10];

// Verify by looking at the first few chars
if (!str_contains($sec1, 'SECTION 1')) die("Part 1 is not Section 1");
if (!str_contains($sec2, 'SECTION 2')) die("Part 2 is not Section 2");
if (!str_contains($sec3, 'SECTION 3')) die("Part 3 is not Section 3");
if (!str_contains($tab3, 'TAB 3')) die("Part 4 is not Tab 3");
if (!str_contains($tab7, 'TAB 7')) die("Part 9 is not Tab 7");

// Rebuild
$tx_content = $header . $sec1 . $sec2 . $tab4 . $tab5 . $tab6 . $tabWeekly . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_transactions.php', $tx_content);

$del_content = $header . $sec3 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_deliveries.php', $del_content);

$adj_content = $header . $tab3 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_adjustments.php', $adj_content);

$pump_content = $header . $tab7 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_pump_master.php', $pump_content);

echo "Explode extraction successful! Lengths: TX=".strlen($tx_content)." DEL=".strlen($del_content)." ADJ=".strlen($adj_content)." PUMP=".strlen($pump_content)."\n";
