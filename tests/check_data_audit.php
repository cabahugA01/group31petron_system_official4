<?php
$c = file_get_contents('public/reports/admin_reports_data.php');
$lines = explode("\n", $c);
foreach ($lines as $i => $l) {
    if (stripos($l, 'transaction_logs') !== false || stripos($l, 'approval_logs') !== false || stripos($l, 'inventory_logs') !== false || stripos($l, 'audit') !== false) {
        echo ($i+1) . ': ' . trim($l) . "\n";
    }
}
