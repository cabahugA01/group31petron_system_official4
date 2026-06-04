<?php
$content = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/public/staff_transactions_hub.php');

$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'section') !== false || strpos($line, 'active_tab') !== false || strpos($line, 'tracker') !== false || strpos($line, 'utang') !== false || strpos($line, 'payment') !== false || strpos($line, 'balances') !== false) {
        if ($i < 600) { // check first 600 lines
            echo ($i + 1) . ": " . trim($line) . "\n";
        }
    }
}
