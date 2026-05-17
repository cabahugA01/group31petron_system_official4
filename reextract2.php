<?php
$source = file_get_contents('public/manager_fuel_management_complete.php');

function get_block($src, $start, $end) {
    $p1 = strpos($src, $start);
    if ($p1 === false) return '';
    $p2 = strpos($src, $end, $p1);
    if ($p2 === false) return substr($src, $p1);
    return substr($src, $p1, $p2 - $p1);
}

// Blocks for manager_fuel_transactions.php
$header = substr($source, 0, strpos($source, '<!-- ----------------------------------------------------------
     SECTION 1:'));
$sec1 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 1:', '<!-- ----------------------------------------------------------
     SECTION 2:');
$sec2 = get_block($source, '<!-- ----------------------------------------------------------
     SECTION 2:', '<!-- ----------------------------------------------------------
     SECTION 3:');
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
$modals = substr($source, strpos($source, '<!-- ----------------------------------------------------------
     MODALS'));

$tx_content = $header . $sec1 . $sec2 . $tab4 . $tab5 . $tab6 . $tabWeekly . "\n</div><!-- /.mfm-wrap -->\n" . $modals;
file_put_contents('public/manager_fuel_transactions.php', $tx_content);

echo "Transactions restored!\n";
