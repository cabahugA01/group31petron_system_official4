<?php
$source = file_get_contents('public/manager_fuel_management_complete.php');

function get_section($src, $markerStr1, $markerStr2) {
    $p1 = strpos($src, $markerStr1);
    if ($p1 === false) return '';
    $start = strrpos(substr($src, 0, $p1), '<!--');
    if ($start === false) $start = $p1;

    $p2 = strpos($src, $markerStr2, $p1);
    if ($p2 === false) return substr($src, $start);
    
    $end = strrpos(substr($src, 0, $p2), '<!--');
    if ($end === false) $end = $p2;

    return substr($src, $start, $end - $start);
}

// 1. Get header (everything before SECTION 1)
$p1 = strpos($source, 'SECTION 1:');
$header_end = strrpos(substr($source, 0, $p1), '<!--');
$header = substr($source, 0, $header_end);

// 2. Get sections
$sec1 = get_section($source, 'SECTION 1:', 'SECTION 2:');
$sec2 = get_section($source, 'SECTION 2:', 'SECTION 3:');
$sec3 = get_section($source, 'SECTION 3:', 'TAB 3:');
$tab3 = get_section($source, 'TAB 3:', 'TAB 4:');
$tab4 = get_section($source, 'TAB 4:', 'TAB 5:');
$tab5 = get_section($source, 'TAB 5:', 'TAB 6:');
$tab6 = get_section($source, 'TAB 6:', 'TAB: WEEKLY');
$tabWeekly = get_section($source, 'TAB: WEEKLY', 'TAB 7:');
$tab7 = get_section($source, 'TAB 7:', 'MODALS');

// 3. Modals
$modals_start = strpos($source, 'MODALS');
$modals_real_start = strrpos(substr($source, 0, $modals_start), '<!--');
$modals = substr($source, $modals_real_start);

// 4. Verification
if (strlen($sec3) < 100) die("Extraction failed for Deliveries!\n");
if (strlen($tab3) < 100) die("Extraction failed for Adjustments!\n");
if (strlen($tab7) < 100) die("Extraction failed for Pump Master!\n");

// Rebuild the 4 files cleanly
$tx_content = $header . $sec1 . $sec2 . $tab4 . $tab5 . $tab6 . $tabWeekly . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_transactions.php', $tx_content);

$del_content = $header . $sec3 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_deliveries.php', $del_content);

$adj_content = $header . $tab3 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_adjustments.php', $adj_content);

$pump_content = $header . $tab7 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_pump_master.php', $pump_content);

echo "True Extraction successful and files rebuilt!\n";
