<?php
$content = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/public/staff_transactions_hub.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'txn-section-title') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
