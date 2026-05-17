<?php
$source = file_get_contents('public/manager_fuel_management_complete.php');

function get_block($src, $start, $end) {
    $p1 = strpos($src, $start);
    if ($p1 === false) return '';
    $p2 = strpos($src, $end, $p1);
    if ($p2 === false) return substr($src, $p1);
    return substr($src, $p1, $p2 - $p1);
}

// Blocks
$header = substr($source, 0, strpos($source, '<!-- ----------------------------------------------------------
     SECTION 1:'));
$sec1 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 1:', '<!-- ----------------------------------------------------------
     SECTION 2:');
$sec2 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 2:', '<!-- ----------------------------------------------------------
     SECTION 3:');
$sec3 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 3:', '<!-- ----------------------------------------------------------
     TAB 3:');
$tab3 = get_block($source, '<!-- ----------------------------------------------------------
     TAB 3:', '<!-- ----------------------------------------------------------
     TAB 4:');
$tab4 = get_block($source, '<!-- ----------------------------------------------------------
     TAB 4:', '<!-- ----------------------------------------------------------
     TAB 5:');
$tab5 = get_block($source, '<!-- ----------------------------------------------------------
     TAB 5:', '<!-- ----------------------------------------------------------
     TAB 6:');
$tab6 = get_block($source, '<!-- ----------------------------------------------------------
     TAB 6:', '<!-- ----------------------------------------------------------
     TAB:');
$tabWeekly = get_block($source, '<!-- ----------------------------------------------------------
     TAB: WEEKLY', '<!-- ----------------------------------------------------------
     TAB 7:');
$tab7 = get_block($source, '<!-- ----------------------------------------------------------
     TAB 7:', '<!-- ----------------------------------------------------------
     MODALS');
$modals = substr($source, strpos($source, '<!-- ----------------------------------------------------------
     MODALS'));

// 1. Transactions
$tx_content = $header . $sec1 . $sec2 . $tab4 . $tab5 . $tab6 . $tabWeekly . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_transactions.php', $tx_content);

// 2. Deliveries
$del_content = $header . $sec3 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_deliveries.php', $del_content);

// 3. Adjustments
$adj_content = $header . $tab3 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_adjustments.php', $adj_content);

// 4. Pump Master
$pump_content = $header . $tab7 . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_pump_master.php', $pump_content);

echo "All files cleanly extracted!\n";
