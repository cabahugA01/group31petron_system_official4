<?php
$source = file_get_contents('public/manager_fuel_management_complete.php');

function get_block($src, $start_marker, $end_marker) {
    $pos1 = strpos($src, $start_marker);
    if ($pos1 === false) return '';
    $pos2 = strpos($src, $end_marker, $pos1);
    if ($pos2 === false) return substr($src, $pos1);
    return substr($src, $pos1, $pos2 - $pos1);
}

// Common header (everything up to SECTION 1)
$header = substr($source, 0, strpos($source, '<!-- ----------------------------------------------------------
     SECTION 1:'));

// Modals (from MODALS to the end)
$modals = substr($source, strpos($source, '<!-- ----------------------------------------------------------
     MODALS'));

// Sections
$s1 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 1:', '<!-- ----------------------------------------------------------
     SECTION 3:');
$s3 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 3:', '<!-- ----------------------------------------------------------
     TAB 3:');
$s4 = get_block($source, '<!-- ----------------------------------------------------------
     TAB 3:', '<!-- ----------------------------------------------------------
     TAB 7:');
// Wait, TAB 7 comes after TAB: WEEKLY / MONTHLY SALES SUMMARY REPORT. 
// Actually, I can just use strpos carefully.

// Let's do this: I will copy the whole file, then use preg_replace to remove the exact blocks I don't want, using non-greedy `.*?` but wrapping them exactly by their HTML comments.

// For manager_fuel_transactions.php:
// KEEP: SECTION 1, SECTION 2, RECONCILIATION, TAB 5, TAB 6, TAB: WEEKLY
// REMOVE: SECTION 3 (Deliveries), TAB 3 (Adjustments), TAB 7 (Pump Master)
$t1 = $source;
$t1 = preg_replace('/<!-- -+.*?SECTION 3: FUEL DELIVERIES.*?<!-- -+.*?TAB 3: ADJUSTMENTS/s', '<!-- ----------------------------------------------------------
     TAB 3: ADJUSTMENTS', $t1);
$t1 = preg_replace('/<!-- -+.*?TAB 3: ADJUSTMENTS.*?<!-- -+.*?TAB 5: VARIANCE REPORTS/s', '<!-- ----------------------------------------------------------
     TAB 5: VARIANCE REPORTS', $t1);
$t1 = preg_replace('/<!-- -+.*?TAB 7: PUMP MASTER.*?<\/div>\s*<\/div>\s*<\/div><!-- \/\.mfm-wrap -->/s', '</div><!-- /.mfm-wrap -->', $t1);
file_put_contents('public/manager_fuel_transactions.php', $t1);

// For manager_fuel_deliveries.php
// KEEP: SECTION 3
$t2 = $source;
$t2 = preg_replace('/<!-- -+.*?SECTION 1: FUEL TRANSACTIONS.*?<!-- -+.*?SECTION 3: FUEL DELIVERIES/s', '<!-- ----------------------------------------------------------
     SECTION 3: FUEL DELIVERIES', $t2);
$t2 = preg_replace('/<!-- -+.*?TAB 3: ADJUSTMENTS.*?<\/div>\s*<\/div>\s*<\/div><!-- \/\.mfm-wrap -->/s', '</div><!-- /.mfm-wrap -->', $t2);
file_put_contents('public/manager_fuel_deliveries.php', $t2);

// For manager_fuel_adjustments.php
// KEEP: TAB 3
$t3 = $source;
$t3 = preg_replace('/<!-- -+.*?SECTION 1: FUEL TRANSACTIONS.*?<!-- -+.*?TAB 3: ADJUSTMENTS/s', '<!-- ----------------------------------------------------------
     TAB 3: ADJUSTMENTS', $t3);
$t3 = preg_replace('/<!-- -+.*?TAB 5: VARIANCE REPORTS.*?<\/div>\s*<\/div>\s*<\/div><!-- \/\.mfm-wrap -->/s', '</div><!-- /.mfm-wrap -->', $t3);
file_put_contents('public/manager_fuel_adjustments.php', $t3);

// For manager_fuel_pump_master.php
// KEEP: TAB 7
$t4 = $source;
$t4 = preg_replace('/<!-- -+.*?SECTION 1: FUEL TRANSACTIONS.*?<!-- -+.*?TAB 7: PUMP MASTER/s', '<!-- ----------------------------------------------------------
     TAB 7: PUMP MASTER', $t4);
file_put_contents('public/manager_fuel_pump_master.php', $t4);

echo "Re-extracted successfully.\n";
