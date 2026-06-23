<?php
$file = 'C:/xampp/htdocs/group31petron_system_official4/public/admin_transactions_oversight.php';
$oversight = file_get_contents($file);

$has_actions_th = strpos($oversight, '>Actions<') !== false;
$has_return_btn = strpos($oversight, 'Return') !== false && strpos($oversight, 'atoOpenRejectModal') !== false;
$has_adjust_btn = strpos($oversight, 'Adjust') !== false && strpos($oversight, 'atoOpenAdjustModal') !== false;
$colspan15      = strpos($oversight, 'colspan="15"') !== false;
$has_iife       = strpos($oversight, '(function() {') !== false;
$actions_col    = strpos($oversight, '<!-- Actions -->') !== false;

echo "Actions <th> header:        " . ($has_actions_th ? 'YES' : 'NO') . "\n";
echo "Return button in rows:      " . ($has_return_btn ? 'YES' : 'NO') . "\n";
echo "Adjust button in rows:      " . ($has_adjust_btn ? 'YES' : 'NO') . "\n";
echo "colspan=15 in empty state:  " . ($colspan15      ? 'YES' : 'NO') . "\n";
echo "IIFE modal fix applied:     " . ($has_iife       ? 'YES' : 'NO') . "\n";
echo "Actions column td:          " . ($actions_col    ? 'YES' : 'NO') . "\n";
